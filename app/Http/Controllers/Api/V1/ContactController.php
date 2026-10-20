<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMailable;
use App\Models\ContactMessage;
use App\Support\ApiResponse;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The contact form on /legal/contact. Bot protection, in order:
 *  1. a honeypot field real visitors never see (filled = silently dropped);
 *  2. a signed, single-use form token that must be a few seconds old (bots post instantly);
 *  3. Cloudflare Turnstile, when its keys are configured;
 *  4. rate limits (throttle:contact) and a link-spam check.
 */
class ContactController extends Controller
{
    public const HONEYPOT = 'website';

    public const MAX_LINKS = 3;

    /** A fresh form token, fetched when the form is shown. */
    public function token(): JsonResponse
    {
        $token = Crypt::encryptString(json_encode(['t' => now()->getTimestamp(), 'n' => Str::random(24)]));

        return ApiResponse::success([
            'token' => $token,
            'turnstile_site_key' => $this->turnstileEnabled() ? config('provoke.contact.turnstile.site_key') : null,
        ], 'Contact form token.')->header('Cache-Control', 'no-store');
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'form_token' => ['required', 'string', 'max:1000'],
            'turnstile_token' => [$this->turnstileEnabled() ? 'required' : 'nullable', 'string', 'max:2048'],
            self::HONEYPOT => ['nullable', 'string', 'max:255'],
        ], [
            'turnstile_token.required' => 'Please complete the security check.',
        ]);

        // Honeypot filled: answer like a success so the bot learns nothing, but store and send nothing.
        if (filled($data[self::HONEYPOT] ?? null)) {
            return $this->sent();
        }

        $this->checkFormToken($data['form_token']);

        if ($this->turnstileEnabled() && ! $this->turnstilePasses($data['turnstile_token'] ?? '', $request)) {
            throw ValidationException::withMessages(['turnstile_token' => 'The security check failed. Please try again.']);
        }

        if (preg_match_all('#https?://|www\.#i', $data['message']) > self::MAX_LINKS) {
            throw ValidationException::withMessages(['message' => 'Please include no more than '.self::MAX_LINKS.' links.']);
        }

        $message = ContactMessage::create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'ip_address' => self::clientIp($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        Mail::to(config('provoke.contact.to'))->queue(new ContactMessageMailable($message));

        return $this->sent();
    }

    /**
     * The visitor's IP. X-Forwarded-For is honoured only from trusted proxies (the web app's own
     * server, see TrustProxies); a forged header from anyone else is ignored.
     */
    public static function clientIp(Request $request): string
    {
        return (string) $request->ip();
    }

    private function sent(): JsonResponse
    {
        return ApiResponse::success(null, 'Thank you. Your message has been sent, and we will reply within 1 business day.', 201);
    }

    private function checkFormToken(string $token): void
    {
        $fail = fn () => throw ValidationException::withMessages(['form_token' => 'This form has expired. Please reload the page and try again.']);

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $fail();
        }

        $age = now()->getTimestamp() - (int) ($payload['t'] ?? 0);
        if ($age < config('provoke.contact.min_seconds')) {
            throw ValidationException::withMessages(['form_token' => 'That was quick! Please take a moment and send again.']);
        }
        if ($age > config('provoke.contact.token_ttl_minutes') * 60) {
            $fail();
        }

        // Single use: a replayed token is refused.
        $ttl = now()->addMinutes(config('provoke.contact.token_ttl_minutes') + 5);
        if (! Cache::add('contact-token:'.hash('sha256', (string) ($payload['n'] ?? '')), true, $ttl)) {
            $fail();
        }
    }

    private function turnstileEnabled(): bool
    {
        return filled(config('provoke.contact.turnstile.site_key')) && filled(config('provoke.contact.turnstile.secret_key'));
    }

    private function turnstilePasses(string $token, Request $request): bool
    {
        try {
            return (bool) Http::asForm()->timeout(6)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('provoke.contact.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => self::clientIp($request),
            ])->json('success', false);
        } catch (Throwable $e) {
            Log::warning('Turnstile verification unavailable', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
