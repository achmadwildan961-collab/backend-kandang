<?php

namespace Tests\Feature;

use Tests\ApiTestCase;

class CoopAccessTest extends ApiTestCase
{
    public function test_regular_user_only_accesses_assigned_coops_and_related_records(): void
    {
        $this->asUser($this->user)->getJson('/api/v1/coops')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->coop->id);
        foreach (['', '/devices', '/sensors/latest', '/sensors/indoor', '/sensors/history', '/settings', '/events'] as $suffix) {
            $this->getJson('/api/v1/coops/'.$this->coop->id.$suffix)->assertOk();
            $this->getJson('/api/v1/coops/'.$this->otherCoop->id.$suffix)->assertForbidden();
        }
        $this->getJson('/api/v1/devices/'.$this->device->id)->assertOk();
        $this->asUser($this->outsider)->getJson('/api/v1/devices/'.$this->device->id)->assertForbidden();
        $this->putJson('/api/v1/coops/'.$this->coop->id.'/settings', $this->settings())->assertForbidden();
    }

    public function test_regular_users_cannot_manage_accounts_coops_devices_or_memberships(): void
    {
        $this->asUser($this->user);
        foreach (['/users', '/users/'.$this->admin->id] as $path) {
            $this->getJson('/api/v1'.$path)->assertForbidden();
        }
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->putJson('/api/v1/users/'.$this->user->id, [])->assertForbidden();
        $this->patchJson('/api/v1/users/'.$this->user->id.'/status', [])->assertForbidden();
        $this->postJson('/api/v1/coops', [])->assertForbidden();
        $this->putJson('/api/v1/coops/'.$this->coop->id, [])->assertForbidden();
        $this->putJson('/api/v1/coops/'.$this->coop->id.'/users', [])->assertForbidden();
        $this->postJson('/api/v1/coops/'.$this->coop->id.'/devices', [])->assertForbidden();
        $this->putJson('/api/v1/devices/'.$this->device->id, [])->assertForbidden();
    }

    public function test_admin_manages_coops_devices_and_assignments(): void
    {
        $this->asUser($this->admin);
        $id = $this->postJson('/api/v1/coops', ['name' => 'New Coop'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/coops/$id/settings")->assertOk()->assertJsonPath('data.gas_threshold', 1500);
        $this->putJson("/api/v1/coops/$id", ['name' => 'Renamed'])->assertOk();
        $this->putJson("/api/v1/coops/$id/users", ['user_ids' => [$this->outsider->id]])->assertOk();
        $body = ['device_identifier' => 'ESP32_DEVICE_B', 'name' => 'ESP32 Device B', 'type' => 'esp32', 'connection_status' => 'online'];
        $device = $this->postJson("/api/v1/coops/$id/devices", $body)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/devices/$device", array_replace($body, ['connection_status' => 'offline']))->assertOk();
        $this->getJson("/api/v1/coops/$id/events")->assertOk()->assertJsonPath('data.0.event_type', 'device_disconnected');
        $this->asUser($this->outsider)->getJson("/api/v1/devices/$device")->assertOk();
        $this->asUser($this->admin)->putJson("/api/v1/coops/$id/users", ['user_ids' => []])->assertOk();
        $this->asUser($this->outsider)->getJson("/api/v1/devices/$device")->assertForbidden();
    }

    public function test_pagination_and_query_validation(): void
    {
        $this->asUser($this->admin)->getJson('/api/v1/coops?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        foreach (['per_page=0', 'per_page=101', 'page=-1'] as $query) {
            $this->getJson('/api/v1/coops?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?from=bad')->assertUnprocessable();
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?from=2026-11-03&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/v1/coops/'.$this->coop->id.'/sensors/history?sensor_type=invalid')->assertUnprocessable();
    }
}
