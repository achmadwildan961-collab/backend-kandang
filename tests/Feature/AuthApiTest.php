<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\ApiTestCase;

class AuthApiTest extends ApiTestCase
{
    public function test_admin_and_user_login_issue_mobile_tokens_without_passwords(): void
    {
        foreach ([$this->admin, $this->user] as $user) {
            $this->app['auth']->forgetGuards();
            $response = $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => 'Test-password-123!', 'device_name' => 'Flutter test']);
            $response->assertOk()->assertJsonPath('data.user.role', $user->role)->assertJsonPath('data.token_type', 'Bearer')->assertJsonMissingPath('data.user.password');
            $token = $response->json('data.token');
            $this->assertNotEmpty($token);
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        }
    }

    public function test_invalid_and_inactive_login_and_rate_limiting(): void
    {
        $body = ['email' => $this->user->email, 'password' => 'wrong', 'device_name' => 'test'];
        $this->postJson('/api/v1/auth/login', $body)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->user->update(['status' => 'inactive']);
        $body['password'] = 'Test-password-123!';
        $this->postJson('/api/v1/auth/login', $body)->assertForbidden();
        $this->assertSame(0, $this->user->tokens()->count());
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', $body)->assertForbidden();
        }
        $this->postJson('/api/v1/auth/login', $body)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_logout_revokes_only_current_token_and_password_change_revokes_all(): void
    {
        $first = $this->user->createToken('first')->plainTextToken;
        $second = $this->user->createToken('second')->plainTextToken;
        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
        $this->putJson('/api/v1/profile/password', ['current_password' => 'Test-password-123!', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertOk();
        $this->assertTrue(Hash::check('New-password-123!', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_inactive_token_is_rejected_even_if_deactivated_outside_api(): void
    {
        $token = $this->user->createToken('old')->plainTextToken;
        $this->user->update(['status' => 'inactive']);
        $this->withToken($token)->getJson('/api/v1/profile')->assertForbidden();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_profile_validation_and_role_escalation_are_blocked(): void
    {
        $this->asUser($this->user);
        $body = ['name' => 'Updated Name', 'email' => 'UPDATED@EXAMPLE.COM', 'phone' => '081234'];
        $this->putJson('/api/v1/profile', $body + ['role' => 'admin'])->assertUnprocessable();
        $this->putJson('/api/v1/profile', $body)->assertOk()->assertJsonPath('data.email', 'updated@example.com')->assertJsonPath('data.role', 'user');
        $this->putJson('/api/v1/profile', ['name' => 'Same email', 'email' => $this->admin->email])->assertUnprocessable();
        $this->putJson('/api/v1/profile/password', ['current_password' => 'wrong', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertUnprocessable();
        $this->postJson('/api/v1/register', [])->assertNotFound();
    }

    public function test_admin_creates_edits_and_deactivates_users_with_token_revocation(): void
    {
        $this->asUser($this->admin);
        $body = ['name' => 'New User', 'email' => 'new@example.com', 'password' => 'User-password-123!', 'password_confirmation' => 'User-password-123!'];
        $created = $this->postJson('/api/v1/users', $body)->assertCreated()->assertJsonPath('data.role', 'user')->assertJsonMissingPath('data.password');
        $id = $created->json('data.id');
        $user = User::findOrFail($id);
        $this->assertTrue(Hash::check($body['password'], $user->password));
        $this->postJson('/api/v1/users', $body)->assertUnprocessable();
        $this->putJson("/api/v1/users/$id", ['name' => 'Edited User', 'email' => 'edited@example.com'])->assertOk()->assertJsonPath('data.name', 'Edited User');
        $token = $user->createToken('phone')->plainTextToken;
        $this->patchJson("/api/v1/users/$id/status", ['status' => 'inactive'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asUser($this->admin)->patchJson("/api/v1/users/$id/status", ['status' => 'active'])->assertOk();
        $this->patchJson('/api/v1/users/'.$this->admin->id.'/status', ['status' => 'inactive'])->assertForbidden();
        $this->postJson('/api/v1/users', $body + ['role' => 'admin'])->assertUnprocessable();
    }

    public function test_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/coops')->assertUnauthorized()->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message', 'errors']);
        $this->get('/api/v1/not-a-route')->assertNotFound()->assertJsonStructure(['message', 'errors']);
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
