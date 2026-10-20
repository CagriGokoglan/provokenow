<?php

namespace App\Services\Auth;

use App\Mail\TwoFactorEmailCodeMailable;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app (TOTP, RFC 6238) two-factor sign-in: Google Authenticator, 1Password, Authy…
 *
 * Lifecycle: begin() stores a new secret and recovery codes, unconfirmed; confirm() switches
 * 2FA on once the app shows a matching code; check() runs at every sign-in; disable() needs the
 * password and a code. Codes are compared in constant time, a code is accepted once (no replay),
 * and wrong attempts are rate limited per account.
 */
class TwoFactor
{
    public const ISSUER = 'PROVOKE NOW';

    public const RECOVERY_CODES = 8;

    /** Wrong codes allowed per account per minute. */
    public const MAX_ATTEMPTS = 5;

    /** Accepts the previous / next 30-second code too (clock drift). */
    private const WINDOW = 1;

    /** Sign-in step between the password and the code (site sign-in). */
    private const CHALLENGE_MINUTES = 5;

    /** "Lost access to device?": a one-time code by email, valid this long. */
    public const EMAIL_CODE_MINUTES = 10;

    /** Email codes allowed per account (and per IP) in EMAIL_CODE_WINDOW minutes. */
    public const EMAIL_CODE_MAX = 3;

    public const EMAIL_CODE_WINDOW = 15;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * New secret + recovery codes, saved but not active until confirm().
     *
     * @return array{secret: string, qr_svg: string, recovery_codes: list<string>}
     */
    public function begin(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map($this->hashCode(...), $codes),
            'two_factor_confirmed_at' => null,
        ])->save();

        return ['secret' => $secret, 'qr_svg' => $this->qrSvg($user, $secret), 'recovery_codes' => $codes];
    }

    /** Activates 2FA once the authenticator app produces a valid code for the pending secret. */
    public function confirm(User $user, string $code): void
    {
        if (blank($user->two_factor_secret)) {
            throw ValidationException::withMessages(['code' => 'Start the setup again.']);
        }

        $this->assertValidCode($user, $code, allowRecovery: false);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    /** Sign-in check: a 6-digit code from the app, or one of the single-use recovery codes. */
    public function check(User $user, string $code): void
    {
        $this->assertValidCode($user, $code, allowRecovery: true);
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    /** @return list<string> fresh codes (shown once); the old ones stop working. */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map($this->hashCode(...), $codes)])->save();

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) $user->two_factor_recovery_codes);
    }

    /**
     * Emails a one-time 6-digit sign-in code to the account's own address (the fallback when the
     * authenticator app is unavailable). Only its keyed hash is cached; a new code replaces the
     * old one. Limited to EMAIL_CODE_MAX per EMAIL_CODE_WINDOW minutes per account and per IP.
     */
    public function sendEmailCode(User $user, ?string $ip = null): void
    {
        $keys = array_filter(['two-factor-email:'.$user->getKey(), $ip ? 'two-factor-email-ip:'.$ip : null]);
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::EMAIL_CODE_MAX)) {
                throw ValidationException::withMessages(['code' => 'Too many email codes. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
            }
        }
        foreach ($keys as $key) {
            RateLimiter::hit($key, self::EMAIL_CODE_WINDOW * 60);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->emailCodeKey($user), $this->hashCode($code), now()->addMinutes(self::EMAIL_CODE_MINUTES));

        // Sent now (not queued): the person is waiting at the sign-in screen.
        Mail::to($user->email)->send(new TwoFactorEmailCodeMailable($code, self::EMAIL_CODE_MINUTES));
    }

    /** Short-lived, encrypted proof that the password was right (site sign-in, step 1 of 2). */
    public function issueChallenge(User $user): string
    {
        return Crypt::encryptString(json_encode(['u' => $user->getKey(), 'e' => now()->addMinutes(self::CHALLENGE_MINUTES)->getTimestamp()]));
    }

    public function userForChallenge(string $challenge): User
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $data = null;
        }

        $user = is_array($data) && ($data['e'] ?? 0) >= now()->getTimestamp() ? User::find($data['u'] ?? null) : null;
        if (! $user?->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'This sign-in has expired. Enter your email and password again.']);
        }

        return $user;
    }

    public function qrSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(self::ISSUER, $user->email, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($url);
    }

    private function assertValidCode(User $user, string $code, bool $allowRecovery): void
    {
        $key = 'two-factor:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $code = trim($code);
        $digits = str_replace(' ', '', $code);
        $ok = preg_match('/^\d{6}$/', $digits)
            // Six digits: the authenticator app, or (at sign-in) a code sent by email.
            ? $this->validTotp($user, $digits) || ($allowRecovery && $this->useEmailCode($user, $digits))
            : ($allowRecovery && $this->useRecoveryCode($user, $code));

        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);
    }

    private function validTotp(User $user, string $code): bool
    {
        if (! $this->google2fa->verifyKey((string) $user->two_factor_secret, $code, self::WINDOW)) {
            return false;
        }

        // Each code works once: a code seen over someone's shoulder can't be replayed.
        return Cache::add('two-factor-used:'.$user->getKey().':'.$code, true, now()->addMinutes(2));
    }

    private function useEmailCode(User $user, string $code): bool
    {
        $stored = Cache::get($this->emailCodeKey($user));
        if (! is_string($stored) || ! hash_equals($stored, $this->hashCode($code))) {
            return false;
        }

        // Single use.
        Cache::forget($this->emailCodeKey($user));

        return true;
    }

    private function emailCodeKey(User $user): string
    {
        return 'two-factor-email-code:'.$user->getKey();
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $given = $this->hashCode($code);
        $codes = (array) $user->two_factor_recovery_codes;

        foreach ($codes as $i => $stored) {
            if (hash_equals((string) $stored, $given)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)), range(1, self::RECOVERY_CODES));
    }

    /** Recovery codes are kept only as keyed hashes; case and dashes don't matter when typed. */
    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', Str::upper(str_replace([' ', '-'], '', trim($code))), (string) config('app.key'));
    }
}
