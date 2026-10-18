<?php

namespace App\Support;

use App\Enums\DeckSegment;
use App\Models\Deck;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/** Absolute URLs on the public Next.js site (provoke.frontend). */
final class FrontendUrl
{
    public static function to(string $path = '/'): string
    {
        return config('provoke.frontend.url').'/'.ltrim($path, '/');
    }

    public static function deck(Deck $deck): string
    {
        return self::path('deck', ['{slug}' => $deck->slug]);
    }

    public static function product(Product $product): string
    {
        return self::path('product', ['{slug}' => $product->slug]);
    }

    /** Signed-in reading view for an owned deck (used in emails; never in the sitemap). */
    public static function libraryDeck(Deck $deck): string
    {
        return self::path('library_deck', ['{slug}' => $deck->slug]);
    }

    public static function deckLevel(DeckSegment $level): string
    {
        return self::path('deck_level', ['{level}' => $level->slug()]);
    }

    public static function storeCategory(ProductCategory $category): string
    {
        return self::path('store_category', ['{slug}' => $category->slug]);
    }

    /**
     * Web page that confirms the address: the API's signed (relative) verification path is
     * carried in the query, and the page posts it back to the API unchanged.
     */
    public static function verifyEmail(User $user): string
    {
        $signed = URL::temporarySignedRoute(
            'v1.auth.verification.verify',
            now()->addMinutes(config('provoke.auth.verification_expire')),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return self::to(config('provoke.frontend.paths.verify_email')).'?'.http_build_query([
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
            ...$query,
        ]);
    }

    public static function post(Post $post): string
    {
        return self::path('post', ['{slug}' => $post->slug]);
    }

    public static function resetPassword(string $token, string $email): string
    {
        return self::to(config('provoke.frontend.paths.reset_password')).'?'.http_build_query(['token' => $token, 'email' => $email]);
    }

    /** @param array<string, string> $replace */
    private static function path(string $key, array $replace): string
    {
        return self::to(strtr(config("provoke.frontend.paths.{$key}"), array_map('rawurlencode', $replace)));
    }
}
