<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Security;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate after the password: staff with 2FA must enter their authenticator code once
 * per session; when 2FA is required (provoke.admin.require_two_factor) staff without it are sent
 * to the Security page to set it up first. Also runs on the panel's Livewire requests.
 */
class RequireTwoFactor
{
    public const SESSION_KEY = 'two_factor_passed';

    /** The code step of the admin sign-in (AdminPanelProvider::routes). */
    public const CHALLENGE_ROUTE = 'filament.admin.two-factor-challenge';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled() && ! self::passed($request, $user)) {
            return $this->deny($request, route(self::CHALLENGE_ROUTE));
        }

        if (! $user->hasTwoFactorEnabled() && config('provoke.admin.require_two_factor') && ! $request->routeIs(Security::getRouteName())) {
            return $this->deny($request, Security::getUrl());
        }

        return $next($request);
    }

    public static function passed(Request $request, User $user): bool
    {
        return session()->get(self::SESSION_KEY) === $user->getKey();
    }

    public static function markPassed(Request $request, User $user): void
    {
        session()->put(self::SESSION_KEY, $user->getKey());
    }

    private function deny(Request $request, string $url): Response
    {
        // A Livewire call from a page loaded before the gate: refuse instead of redirecting.
        if ($request->hasHeader('X-Livewire')) {
            abort(403, 'Two-factor authentication required.');
        }

        return redirect()->guest($url);
    }
}
