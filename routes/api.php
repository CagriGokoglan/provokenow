<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\DeckController;
use App\Http\Controllers\Api\V1\OrderFulfillmentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use App\Http\Controllers\Api\V1\User\EntitlementController;
use App\Http\Controllers\Api\V1\User\VaultController;
use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public routes resolve the user optionally via $request->user('sanctum'): a valid Bearer
| token personalises the response (e.g. unlocked decks, account cart) but is not required.
*/
Route::prefix('v1')->name('v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    Route::get('config/public', PublicConfigController::class)->name('config.public');

    // Catalogue (public)
    Route::get('decks', [DeckController::class, 'index'])->name('decks.index');
    Route::get('decks/{deckCode}', [DeckController::class, 'show'])->name('decks.show');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');

    // Cart & checkout (guests via X-Cart-Token, or signed in)
    Route::prefix('cart')->name('cart.')->middleware('throttle:cart')->group(function () {
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::post('add', [CartController::class, 'add'])->name('add');
        Route::post('apply-upsell', [CartController::class, 'applyUpsell'])->name('apply-upsell');
        Route::delete('items/{itemId}', [CartController::class, 'remove'])->whereUuid('itemId')->name('items.destroy');
    });
    Route::post('checkout/intent', [CheckoutController::class, 'intent'])->middleware('throttle:cart')->name('checkout.intent');
    Route::post('checkout/session', [CheckoutController::class, 'session'])->middleware('throttle:cart')->name('checkout.session');
    Route::get('checkout/status/{sessionId}', [CheckoutController::class, 'status'])
        ->where('sessionId', 'cs_[A-Za-z0-9_]{10,255}')
        ->middleware('throttle:cart')
        ->name('checkout.status');

    // TEST MODE payment page actions (404 unless TestPaymentGateway::allowed()).
    Route::prefix('checkout/test/{sessionId}')->where(['sessionId' => 'cs_test_[A-Za-z0-9_]{10,255}'])->middleware('throttle:cart')->group(function () {
        Route::post('pay', [CheckoutController::class, 'testPay'])->name('checkout.test.pay');
        Route::post('cancel', [CheckoutController::class, 'testCancel'])->name('checkout.test.cancel');
    });

    // Payment provider webhooks (signature-verified, no auth / throttle).
    Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

    // Account
    Route::middleware('auth:sanctum')->prefix('user')->name('user.')->group(function () {
        Route::get('entitlements', [EntitlementController::class, 'index'])->name('entitlements.index');
        Route::post('vault/passcode', [VaultController::class, 'setPasscode'])->middleware('throttle:vault')->name('vault.passcode');
        Route::post('vault/verify', [VaultController::class, 'verify'])->middleware('throttle:vault')->name('vault.verify');
    });

    // Simulated payment webhook — test environments only, never in production.
    if (config('provoke.enable_test_fulfillment') && ! app()->isProduction()) {
        Route::post('orders/fulfill-test', [OrderFulfillmentController::class, 'fulfillTest'])
            ->middleware(['auth:sanctum', 'staff'])
            ->name('orders.fulfill-test');
    }
});
