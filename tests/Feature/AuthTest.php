<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sup3rSecretPass';

    public function test_register_creates_customer_with_profile_and_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ava',
            'email' => 'AVA@Example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => 'superadmin', // must be ignored
            'marketing_opt_in' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ava@example.com')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.profile.marketing_opt_in', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['success', 'data' => ['user' => ['id'], 'token'], 'message']);

        $user = User::firstWhere('email', 'ava@example.com');
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertTrue(Str::isUuid($user->id));
        $this->assertNotNull($user->profile);
    }

    public function test_register_validation_errors_use_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_login_returns_token_and_rejects_bad_password(): void
    {
        User::factory()->create(['email' => 'ava@example.com', 'password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', ['email' => 'ava@example.com', 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/auth/login', ['email' => 'Ava@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_me_and_logout_revoke_current_token(): void
    {
        User::factory()->create(['email' => 'ava@example.com', 'password' => self::PASSWORD]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'ava@example.com', 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ava@example.com');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'data' => null, 'message' => 'Unauthenticated.']);
    }
}
