<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Stand-in for Stripe during internal UI/UX testing. Redirects to the web app's TEST MODE
 * payment page, which confirms through CheckoutController::testPay → OrderFulfillmentService,
 * exactly like the Stripe webhook does.
 *
 * Only selected when Stripe has no keys, ENABLE_TEST_FULFILLMENT=true and the app is not in
 * production (see AppServiceProvider).
 */
class TestPaymentGateway implements PaymentGateway
{
    public static function allowed(): bool
    {
        return (bool) config('provoke.enable_test_fulfillment') && ! app()->isProduction();
    }

    public function name(): string
    {
        return 'test';
    }

    public function isConfigured(): bool
    {
        return self::allowed();
    }

    public function createCheckout(Order $order): CheckoutRedirect
    {
        // Same shape as Stripe session ids so the web app's routes and validation are shared.
        $reference = 'cs_test_'.Str::lower(Str::random(32));
        $url = config('provoke.frontend.url').'/checkout/test?session_id='.$reference;

        return new CheckoutRedirect($url, $reference);
    }
}
