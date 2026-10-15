<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\FrontendUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    /** The page receives id/hash/expires/signature and posts them back to the API path. */
    private function verifyPathFrom(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return "/api/v1/auth/email/verify/{$q['id']}/{$q['hash']}?".http_build_query(['expires' => $q['expires'], 'signature' => $q['signature']]);
    }

    public function test_registration_queues_a_verification_email_linking_to_the_web_app(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ava', 'email' => 'Ava@Example.com', 'password' => 'Secret-Pass-1', 'password_confirmation' => 'Secret-Pass-1',
        ])->assertCreated();

        $user = User::firstWhere('email', 'ava@example.com');
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user) {
            $mail = $n->toMail($user);
            $this->assertStringStartsWith(config('provoke.frontend.url').'/auth/verify-email?id='.$user->id, $mail->viewData['url']);
            $this->assertStringContainsString('Verify Your', view($mail->view, $mail->viewData)->render());

            return $n instanceof ShouldQueue;
        });
    }

    public function test_a_signed_link_verifies_the_address_once(): void
    {
        $user = User::factory()->unverified()->create();
        $path = $this->verifyPathFrom(FrontendUrl::verifyEmail($user));

        $this->postJson($path)->assertOk()->assertJsonPath('data.already', false);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->postJson($path)->assertOk()->assertJsonPath('data.already', true);
    }

    public function test_tampered_and_expired_links_are_refused_with_a_reason(): void
    {
        $user = User::factory()->unverified()->create();
        $path = $this->verifyPathFrom(FrontendUrl::verifyEmail($user));

        $this->postJson(str_replace('signature=', 'signature=x', $path))->assertForbidden()->assertJsonPath('errors.reason.0', 'invalid');
        $this->postJson(str_replace(sha1($user->email), sha1('someone@else.test'), $path))->assertForbidden()->assertJsonPath('errors.reason.0', 'invalid');

        $this->travel(config('provoke.auth.verification_expire') + 1)->minutes();
        $this->postJson($path)->assertForbidden()->assertJsonPath('errors.reason.0', 'expired');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_from_an_expired_link_or_while_signed_in(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/email/resend', ['id' => $user->id])->assertOk();
        $this->postJson('/api/v1/auth/email/resend', ['id' => User::factory()->create()->id])->assertOk(); // verified: nothing sent
        $this->withToken($user->createToken('web')->plainTextToken)->postJson('/api/v1/auth/email/resend')->assertOk();

        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2);
        Notification::assertCount(2);
    }

    public function test_forgot_password_never_reveals_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ava@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'AVA@example.com'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $url = $n->toMail($user)->viewData['url'];

            return str_starts_with($url, config('provoke.frontend.url').'/auth/reset-password?token=') && str_contains($url, 'email=ava%40example.com');
        });
    }

    public function test_reset_changes_the_password_signs_out_everywhere_and_works_once(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ava@example.com']);
        $user->createToken('phone');
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'ava@example.com', 'password' => 'New-Secret-22', 'password_confirmation' => 'New-Secret-22'];

        $this->postJson('/api/v1/auth/reset-password', [...$payload, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $user->refresh();
        $this->assertTrue(password_verify('New-Secret-22', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue($user->hasVerifiedEmail(), 'Following the emailed link proves the address.');

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable()->assertJsonPath('errors.token.0', 'invalid');
        $this->postJson('/api/v1/auth/login', ['email' => 'ava@example.com', 'password' => 'New-Secret-22'])->assertOk();
    }

    public function test_the_brevo_mailer_is_available(): void
    {
        config(['mail.mailers.brevo.key' => 'test-key']);

        $this->assertStringStartsWith('brevo+api://', (string) app('mail.manager')->mailer('brevo')->getSymfonyTransport());
    }
}
