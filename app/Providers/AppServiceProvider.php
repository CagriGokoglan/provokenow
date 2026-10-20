<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Api\V1\ContactController;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\TestPaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stripe whenever it has keys. Without keys, non-production environments with
        // ENABLE_TEST_FULFILLMENT=true get the TEST MODE gateway for internal flow testing.
        $this->app->bind(PaymentGateway::class, function ($app) {
            $stripe = $app->make(StripeGateway::class);

            return ! $stripe->isConfigured() && TestPaymentGateway::allowed()
                ? $app->make(TestPaymentGateway::class)
                : $stripe;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', fn (array $config) => (new BrevoTransportFactory)->create(
            new Dsn('brevo+api', 'default', (string) $config['key'])
        ));

        // Catch lazy-loading / silently discarded attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('auth', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            // Per visitor, and per account when an email is given (the two-factor step has none;
            // its codes are limited per account inside TwoFactor).
            return array_values(array_filter([
                Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
                $email !== '' ? Limit::perMinute(5)->by('auth-email:'.$email) : null,
            ]));
        });

        RateLimiter::for('club', fn (Request $request) => Limit::perMinute(5)->by('club:'.$request->ip()));

        RateLimiter::for('contact-token', fn (Request $request) => Limit::perMinute(20)->by('contact-token:'.ContactController::clientIp($request)));

        // Contact form: per visitor, per sender address, and a site-wide ceiling.
        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinute(3)->by('contact-ip:'.ContactController::clientIp($request)),
            Limit::perHour(5)->by('contact-email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perHour(60)->by('contact-all'),
        ]);

        // Passcodes are short; keep brute force impractical.
        RateLimiter::for('vault', fn (Request $request) => Limit::perMinute(5)->by('vault:'.$request->user()?->getKey()));

        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(60)->by(
            'cart:'.($request->user('sanctum')?->getKey() ?? $request->ip())
        ));
    }
}
