<?php

namespace Tests\Feature;

use Tests\ApiTestCase;

class CsvExportTest extends ApiTestCase
{
    private function uri(array $query = []): string
    {
        return '/api/v1/coops/'.$this->coop->id.'/sensors/export?'.http_build_query($query + ['from' => '2026-11-03T00:00:00Z', 'to' => '2026-11-03T23:59:59.999999Z']);
    }

    public function test_export_requires_authentication_and_coop_access(): void
    {
        $this->getJson($this->uri())->assertUnauthorized();
        $this->asUser($this->outsider)->getJson($this->uri())->assertForbidden();
        $this->asUser($this->admin)->getJson($this->uri())->assertOk()->assertHeader('X-Export-Rows', '0');
        $this->asUser($this->user)->getJson($this->uri())->assertOk();
    }

    public function test_csv_has_excel_bom_units_actual_values_and_empty_missing_fields(): void
    {
        $this->readings();
        $response = $this->asUser($this->user)->getJson($this->uri())->assertOk()->assertHeader('X-Export-Rows', '1');
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", substr($csv, 3));
        $header = str_getcsv($lines[0], ',', '"', '');
        $this->assertSame(['Timestamp (UTC)', 'Indoor Average Temperature (°C)', 'Outdoor Temperature (°C)', 'Humidity (%)', 'Gas (ADC)'], $header);
        $row = str_getcsv($lines[1], ',', '"', '');
        $this->assertSame(['2026-11-03T08:00:00.000000Z', '30.38', '29.2', '', '1200'], $row);
        $this->assertStringContainsString('coop-'.$this->coop->id.'-sensors-', $response->headers->get('Content-Disposition'));
    }

    public function test_incomplete_batches_and_date_filters_do_not_fabricate_data(): void
    {
        $this->reading('indoor_dht22_1', 30);
        $this->reading('outdoor_temperature', 24);
        $this->reading('gas_adc', 999, now()->subDay());
        $csv = $this->asUser($this->user)->getJson($this->uri())->assertOk()->getContent();
        $row = str_getcsv(explode("\r\n", $csv)[1], ',', '"', '');
        $this->assertSame(['2026-11-03T08:00:00.000000Z', '', '24', '', ''], $row);
        $this->getJson($this->uri(['from' => '2026-11-04', 'to' => '2026-11-05']))->assertOk()->assertHeader('X-Export-Rows', '0');
        $this->getJson($this->uri(['from' => '2026-11-05']))->assertUnprocessable();
        $this->getJson($this->uri(['from' => '2020-01-01']))->assertUnprocessable();
    }
}
