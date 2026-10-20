<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Filament\Pages\Security;
use App\Filament\Pages\TwoFactorChallenge;
use App\Http\Middleware\RequireTwoFactor;
use App\Mail\TwoFactorEmailCodeMailable;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        $admin = User::factory()->create(['password' => 'Admin-Pass-123', ...$attributes]);
        $admin->forceFill(['role' => UserRole::Admin])->save();

        return $admin;
    }

    private function codeFor(User $user, int $offset = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp((string) $user->fresh()->two_factor_secret, $g->getTimestamp() + $offset);
    }

    /** @return list<string> recovery codes */
    private function enable(User $user): array
    {
        $setup = app(TwoFactor::class)->begin($user);
        app(TwoFactor::class)->confirm($user, $this->codeFor($user));

        return $setup['recovery_codes'];
    }

    public function test_setup_needs_a_valid_code_and_codes_work_once(): void
    {
        $admin = $this->admin();
        $setup = app(TwoFactor::class)->begin($admin);

        $this->assertStringContainsString('<svg', $setup['qr_svg']);
        $this->assertCount(8, $setup['recovery_codes']);
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled(), 'Not on until confirmed.');
        $this->assertNotSame($setup['secret'], $admin->fresh()->getRawOriginal('two_factor_secret'), 'Encrypted at rest.');

        try {
            app(TwoFactor::class)->confirm($admin, '000000');
            $this->fail('Wrong code accepted.');
        } catch (ValidationException) {
        }

        $code = $this->codeFor($admin);
        app(TwoFactor::class)->confirm($admin, $code);
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());

        // The same code can't be replayed.
        $this->expectException(ValidationException::class);
        app(TwoFactor::class)->check($admin->fresh(), $code);
    }

    public function test_recovery_codes_are_single_use_and_attempts_are_limited(): void
    {
        $admin = $this->admin();
        $codes = $this->enable($admin);

        app(TwoFactor::class)->check($admin->fresh(), strtolower($codes[0]));
        $this->assertSame(7, app(TwoFactor::class)->remainingRecoveryCodes($admin->fresh()));
        try {
            app(TwoFactor::class)->check($admin->fresh(), $codes[0]);
            $this->fail('A recovery code worked twice.');
        } catch (ValidationException) {
        }

        foreach (range(1, 4) as $i) {
            try {
                app(TwoFactor::class)->check($admin->fresh(), '111111');
            } catch (ValidationException) {
            }
        }
        // Locked: even the right code is refused until the minute passes.
        try {
            app(TwoFactor::class)->check($admin->fresh(), $this->codeFor($admin, 1));
            $this->fail('No lockout.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many attempts', $e->errors()['code'][0]);
        }
    }

    public function test_site_sign_in_asks_for_the_code_before_issuing_a_token(): void
    {
        $admin = $this->admin(['email' => 'staff@example.com']);
        $this->enable($admin);

        $step1 = $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Admin-Pass-123'])
            ->assertOk()->assertJsonPath('data.requires_2fa', true)->assertJsonMissingPath('data.token');
        $challenge = $step1->json('data.challenge');

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => 'forged', 'code' => '123456'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($admin, 1)])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        // Challenges expire.
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeFor($admin)])->assertUnprocessable();

        // Accounts without 2FA sign in as before.
        User::factory()->create(['email' => 'member@example.com', 'password' => 'Member-Pass-123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'Member-Pass-123'])->assertJsonStructure(['data' => ['token']]);
    }

    public function test_admin_panel_requires_the_code_each_session(): void
    {
        $admin = $this->admin();
        $this->enable($admin);

        $this->actingAs($admin)->get('/admin')->assertRedirect(route(RequireTwoFactor::CHALLENGE_ROUTE));
        $this->get(route(RequireTwoFactor::CHALLENGE_ROUTE))->assertOk()->assertSee('authenticator app');

        Livewire::test(TwoFactorChallenge::class)->set('data.code', '000000')->call('verify')->assertHasErrors(['code']);
        Livewire::test(TwoFactorChallenge::class)->set('data.code', $this->codeFor($admin, 1))->call('verify')->assertHasNoErrors()->assertRedirect();

        $this->get('/admin')->assertOk();
    }

    public function test_staff_without_2fa_are_sent_to_set_it_up_and_can_turn_it_on(): void
    {
        config(['provoke.admin.require_two_factor' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertRedirect(Security::getUrl());
        $this->get(Security::getUrl())->assertOk()->assertSee('Enable two-factor authentication');

        $page = Livewire::test(Security::class)->call('startSetup')->assertSet('manualKey', fn ($key) => filled($key));
        $page->set('code', '000000')->call('confirmSetup')->assertHasErrors(['code']);
        $page->set('code', $this->codeFor($admin))->call('confirmSetup')->assertHasNoErrors()->assertSet('recoveryCodes', fn ($codes) => count($codes) === 8);

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->get('/admin')->assertOk();

        // Turning it off needs the password and a code.
        Livewire::test(Security::class)
            ->callAction('disable', data: ['password' => 'wrong', 'code' => $this->codeFor($admin, 1)])
            ->assertHasActionErrors();
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        Livewire::test(Security::class)->callAction('disable', data: ['password' => 'Admin-Pass-123', 'code' => $this->codeFor($admin, 1)]);
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    private function sentEmailCode(): string
    {
        $code = null;
        Mail::assertSent(TwoFactorEmailCodeMailable::class, function (TwoFactorEmailCodeMailable $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return (string) $code;
    }

    public function test_lost_device_email_code_signs_in_once_and_is_limited(): void
    {
        Mail::fake();
        $admin = $this->admin(['email' => 'staff@example.com']);
        $this->enable($admin);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Admin-Pass-123'])->json('data.challenge');

        $response = $this->postJson('/api/v1/auth/two-factor/email', ['challenge' => $challenge])->assertOk()->assertJsonPath('data.cooldown_seconds', 60);
        $this->assertStringNotContainsString('staff@example.com', $response->getContent(), 'Never shows the address.');
        Mail::assertSent(TwoFactorEmailCodeMailable::class, fn ($m) => $m->hasTo('staff@example.com') && $m->envelope()->subject === 'Admin Panel Login Verification Code');
        $code = $this->sentEmailCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringContainsString($code, (new TwoFactorEmailCodeMailable($code, 10))->render());

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable(); // single use

        // Expires after 10 minutes.
        $this->postJson('/api/v1/auth/two-factor/email', ['challenge' => $challenge])->assertOk();
        $late = $this->sentEmailCode();
        $this->travel(11)->minutes();
        $fresh = $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Admin-Pass-123'])->json('data.challenge');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $fresh, 'code' => $late])->assertUnprocessable();

        // Three emails per 15 minutes: the third is sent, the fourth refused.
        $this->travelBack();
        $this->postJson('/api/v1/auth/two-factor/email', ['challenge' => $fresh])->assertOk();
        $this->postJson('/api/v1/auth/two-factor/email', ['challenge' => $fresh])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_admin_code_screen_can_send_an_email_code(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->enable($admin);
        $this->actingAs($admin);

        Livewire::test(TwoFactorChallenge::class)
            ->assertSee('Lost access to device? Send code via email')
            ->call('sendEmailCode')
            ->assertNotified('Security code sent')
            ->assertSet('emailCooldown', 60)
            ->assertSee('registered email address')
            ->assertDontSee($admin->email);

        Livewire::test(TwoFactorChallenge::class)->set('data.code', $this->sentEmailCode())->call('verify')->assertHasNoErrors()->assertRedirect();
        $this->get('/admin')->assertOk();
    }

    public function test_customers_cannot_reach_the_admin_or_its_2fa_pages(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertForbidden();
        $this->get(Security::getUrl())->assertForbidden();
    }

    public function test_reset_command_turns_2fa_off_for_a_locked_out_account(): void
    {
        $admin = $this->admin(['email' => 'lost@example.com']);
        $this->enable($admin);

        $this->artisan('admin:reset-2fa lost@example.com')->assertSuccessful();
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }
}
