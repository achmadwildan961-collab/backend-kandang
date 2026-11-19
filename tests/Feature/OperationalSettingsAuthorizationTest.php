<?php

namespace Tests\Feature;

use Tests\ApiTestCase;

class OperationalSettingsAuthorizationTest extends ApiTestCase
{
    public function test_settings_permission_matrix(): void
    {
        $url = '/api/v1/coops/'.$this->coop->id.'/settings';
        $other = '/api/v1/coops/'.$this->otherCoop->id.'/settings';
        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, $this->settings())->assertUnauthorized();
        $this->asUser($this->user)->getJson($url)->assertOk();
        $this->putJson($url, $this->settings(['gas_threshold' => 42]))->assertForbidden();
        $this->putJson($url, [])->assertForbidden();
        $this->getJson($other)->assertForbidden();
        $this->putJson($other, $this->settings())->assertForbidden();
        $this->asUser($this->outsider)->putJson($url, $this->settings())->assertForbidden();
        $this->assertSame(1500.0, (float) $this->coop->settings()->first()->gas_threshold);
        $this->asUser($this->admin)->putJson($url, $this->settings(['gas_threshold' => 1000]))->assertOk();
        $this->admin->update(['status' => 'inactive']);
        $this->asUser($this->admin)->putJson($url, $this->settings())->assertForbidden();
    }
}
