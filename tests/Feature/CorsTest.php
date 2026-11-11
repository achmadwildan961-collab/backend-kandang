<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\ApiTestCase;

class CorsTest extends ApiTestCase
{
    private const ORIGINS = ['http://localhost:58111', 'http://127.0.0.1:58111'];

    private function assertCors(TestResponse $response, string $origin): void
    {
        $response->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertHeaderMissing('Location');
    }

    public function test_preflight_allows_login_and_existing_authenticated_methods_without_a_token(): void
    {
        foreach (self::ORIGINS as $origin) {
            foreach (['POST' => 'auth/login', 'GET' => 'auth/me', 'PUT' => 'profile', 'PATCH' => 'notifications/read-all'] as $method => $path) {
                $response = $this->withHeaders([
                    'Origin' => $origin,
                    'Access-Control-Request-Method' => $method,
                    'Access-Control-Request-Headers' => 'authorization,content-type,accept',
                ])->options('/api/v1/'.$path);

                $response->assertNoContent();
                $this->assertCors($response, $origin);
                $this->assertContains($method, explode(', ', $response->headers->get('Access-Control-Allow-Methods')));
                foreach (['authorization', 'content-type', 'accept'] as $header) {
                    $this->assertContains($header, explode(', ', strtolower($response->headers->get('Access-Control-Allow-Headers'))));
                }
            }
        }
    }

    public function test_both_roles_can_login_use_bearer_tokens_and_logout_with_cors(): void
    {
        foreach (self::ORIGINS as $origin) {
            foreach ([$this->admin, $this->user] as $user) {
                $this->flushHeaders()->withHeader('Origin', $origin);
                $this->app['auth']->forgetGuards();
                $login = $this->postJson('/api/v1/auth/login', [
                    'email' => $user->email,
                    'password' => 'Test-password-123!',
                    'device_name' => 'CORS regression test',
                ])->assertOk()->assertJsonPath('data.user.role', $user->role)
                    ->assertJsonPath('data.token_type', 'Bearer');
                $this->assertCors($login, $origin);

                $this->withToken($login->json('data.token'));
                $this->app['auth']->forgetGuards();
                $this->assertCors($this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id), $origin);
                $this->assertCors($this->getJson('/api/v1/coops')->assertOk(), $origin);
                $this->assertCors($this->getJson('/api/v1/users')->assertStatus($user->role === 'admin' ? 200 : 403), $origin);
                $this->assertCors($this->postJson('/api/v1/auth/logout')->assertOk(), $origin);
                $this->app['auth']->forgetGuards();
                $this->assertCors($this->getJson('/api/v1/auth/me')->assertUnauthorized(), $origin);
            }
        }
    }

    public function test_login_validation_inactive_and_throttled_errors_keep_cors_headers(): void
    {
        $origin = self::ORIGINS[0];
        $this->withHeader('Origin', $origin);
        $this->assertCors($this->postJson('/api/v1/auth/login', [])->assertUnprocessable(), $origin);
        $body = ['email' => $this->user->email, 'password' => 'wrong', 'device_name' => 'CORS test'];
        $this->assertCors($this->postJson('/api/v1/auth/login', $body)->assertUnprocessable(), $origin);
        $this->user->update(['status' => 'inactive']);
        $body['password'] = 'Test-password-123!';
        for ($i = 0; $i < 4; $i++) {
            $this->assertCors($this->postJson('/api/v1/auth/login', $body)->assertForbidden(), $origin);
        }
        $this->assertCors($this->postJson('/api/v1/auth/login', $body)->assertStatus(429), $origin);
    }

    public function test_unauthenticated_missing_route_and_server_errors_keep_cors_headers(): void
    {
        Route::get('/api/v1/cors-test-error', fn () => throw new \RuntimeException('CORS test exception'));
        foreach (self::ORIGINS as $origin) {
            $this->withHeader('Origin', $origin);
            $this->assertCors($this->getJson('/api/v1/auth/me')->assertUnauthorized(), $origin);
            $this->assertCors($this->getJson('/api/v1/not-a-route')->assertNotFound(), $origin);
            $this->assertCors($this->getJson('/api/v1/cors-test-error')->assertStatus(500), $origin);
        }
    }

    public function test_unlisted_origins_receive_no_cors_permission(): void
    {
        foreach (['https://untrusted.example', 'http://localhost:58112'] as $origin) {
            $this->withHeaders([
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type',
            ])->options('/api/v1/auth/login')->assertHeaderMissing('Access-Control-Allow-Origin');
            $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()
                ->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }
}
