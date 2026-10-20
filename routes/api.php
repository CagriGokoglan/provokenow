<?php

use App\Http\Controllers\Api\V1\Account\AccountController;
use App\Http\Controllers\Api\V1\Account\AddressController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\BlogController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ClubController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DeckController;
use App\Http\Controllers\Api\V1\Gifts\GiftController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\LegalPageController;
use App\Http\Controllers\Api\V1\OrderFulfillmentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use App\Http\Controllers\Api\V1\TaxonomyController;
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
            Route::post('two-factor', [AuthController::class, 'twoFactor'])->name('two-factor');
            Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
            Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
        });

        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.resend');

        Route::post('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:12,1')
            ->whereUuid('id')
            ->where('hash', '[0-9a-f]{40}')
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    // Public catalogue reads: cached (busted on every content change).
    Route::middleware('cache.public')->group(function () {
        Route::get('config/public', PublicConfigController::class)->name('config.public');
        Route::get('home', HomeController::class)->name('home');
        Route::get('taxonomies', TaxonomyController::class)->name('taxonomies');
        Route::get('decks', [DeckController::class, 'index'])->name('decks.index');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
        Route::get('blog/posts', [BlogController::class, 'index'])->name('blog.index');
        Route::get('blog/categories', [BlogController::class, 'categories'])->name('blog.categories');
        Route::get('blog/posts/{slug}', [BlogController::class, 'show'])->name('blog.show');
        Route::get('legal-pages', [LegalPageController::class, 'index'])->name('legal.index');
        Route::get('legal-pages/{slug}', [LegalPageController::class, 'show'])->name('legal.show');
    });
    Route::post('club/subscribe', [ClubController::class, 'subscribe'])->middleware('throttle:club')->name('club.subscribe');
    Route::get('contact/token', [ContactController::class, 'token'])->middleware('throttle:contact-token')->name('contact.token');
    Route::post('contact', [ContactController::class, 'send'])->middleware('throttle:contact')->name('contact.send');

    // Catalogue (public)
    // Personalised (full cards for owners), so never cached.
    Route::get('decks/{deckCode}', [DeckController::class, 'show'])->name('decks.show');

    // Cart & checkout (guests via X-Cart-Token, or signed in)
    Route::prefix('cart')->name('cart.')->middleware('throttle:cart')->group(function () {
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::post('add', [CartController::class, 'add'])->name('add');
        Route::post('apply-upsell', [CartController::class, 'applyUpsell'])->name('apply-upsell');
        Route::delete('items/{itemId}', [CartController::class, 'remove'])->whereUuid('itemId')->name('items.destroy');
    });
    Route::post('checkout/intent', [CheckoutController::class, 'intent'])->middleware('throttle:cart')->name('checkout.intent');
    Route::post('checkout/session', [CheckoutController::class, 'session'])->middleware('throttle:cart')->name('checkout.session');
    Route::post('checkout/gift', [GiftController::class, 'checkout'])->middleware(['auth:sanctum', 'throttle:cart'])->name('checkout.gift');
    Route::get('gifts/claim/{token}', [GiftController::class, 'show'])->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:30,1')->name('gifts.show');
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
        Route::get('orders', [AccountController::class, 'orders'])->name('orders.index');
        Route::patch('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::put('password', [AccountController::class, 'updatePassword'])->middleware('throttle:auth')->name('password.update');
        Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::patch('addresses/{id}', [AddressController::class, 'update'])->whereUuid('id')->name('addresses.update');
        Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->whereUuid('id')->name('addresses.destroy');
        Route::post('vault/passcode', [VaultController::class, 'setPasscode'])->middleware('throttle:vault')->name('vault.passcode');
        Route::post('vault/verify', [VaultController::class, 'verify'])->middleware('throttle:vault')->name('vault.verify');
        Route::get('gifts/pending', [GiftController::class, 'pending'])->name('gifts.pending');
        Route::post('gifts/attach', [GiftController::class, 'attach'])->middleware('throttle:10,1')->name('gifts.attach');
        Route::post('gifts/{id}/claim', [GiftController::class, 'claim'])->whereUuid('id')->name('gifts.claim');
    });

    // Simulated payment webhook — test environments only, never in production.
    if (config('provoke.enable_test_fulfillment') && ! app()->isProduction()) {
        Route::post('orders/fulfill-test', [OrderFulfillmentController::class, 'fulfillTest'])
            ->middleware(['auth:sanctum', 'staff'])
            ->name('orders.fulfill-test');
    }
});
