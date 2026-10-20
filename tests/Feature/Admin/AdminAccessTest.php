<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_guests_are_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_customers_are_forbidden(): void
    {
        $this->actingAs($this->user(UserRole::Customer))->get('/admin')->assertForbidden();
    }

    public function test_admins_and_superadmins_can_open_every_section(): void
    {
        // Past the two-factor step (covered by TwoFactorTest).
        config(['provoke.admin.require_two_factor' => false]);

        foreach ([UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $this->actingAs($this->user($role));

            foreach ([
                '/admin', '/admin/decks', '/admin/decks/create', '/admin/products', '/admin/products/create', '/admin/orders', '/admin/users',
                '/admin/deck-categories', '/admin/product-categories', '/admin/hero-slides', '/admin/hero-slides/create',
                '/admin/instagram-posts', '/admin/site-settings', '/admin/club-signups', '/admin/gifts', '/admin/security',
            ] as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_customer_credentials_are_rejected_by_the_admin_login_form(): void
    {
        $this->user(UserRole::Customer, ['email' => 'ava@example.com', 'password' => 'Customer-Pass-1']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'ava@example.com', 'password' => 'Customer-Pass-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_staff_can_log_in_through_the_admin_form(): void
    {
        $admin = $this->user(UserRole::Admin, ['email' => 'ops@provokenow.com', 'password' => 'Admin-Pass-123']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'ops@provokenow.com', 'password' => 'Admin-Pass-123'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_admin_session_does_not_authenticate_the_api(): void
    {
        $this->actingAs($this->user(UserRole::SuperAdmin), 'web');

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/user/entitlements')->assertUnauthorized();
    }

    public function test_api_token_does_not_grant_admin_panel_access(): void
    {
        $token = $this->user(UserRole::SuperAdmin)->createToken('api')->plainTextToken;

        $this->withToken($token)->get('/admin')->assertRedirect('/admin/login');
    }
}
