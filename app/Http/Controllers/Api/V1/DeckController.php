<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeckSegment;
use App\Http\Controllers\Controller;
use App\Http\Resources\CardResource;
use App\Http\Resources\DeckResource;
use App\Http\Resources\ProductResource;
use App\Models\Deck;
use App\Services\Access\VaultService;
use App\Services\Commerce\DeckEditions;
use App\Services\Seo\SchemaGeneratorService;
use App\Services\Seo\SeoMetadataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeckController extends Controller
{
    public const LOCK_NOT_ENTITLED = 'not_entitled';

    public const LOCK_VAULT = 'vault_locked';

    public const SORTS = ['featured', 'bestsellers', 'newest', 'price_asc', 'price_desc'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'segment' => ['sometimes', Rule::enum(DeckSegment::class)],
            // Web-app slugs: light-ignition | medium-spice | advanced | taboo
            'level' => ['sometimes', 'string', Rule::in(array_map(fn (DeckSegment $s) => $s->slug(), DeckSegment::cases()))],
            'category' => ['sometimes', 'string', 'max:80'],
            'intensity_level' => ['sometimes', 'integer', 'between:1,4'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        // Metadata columns only: no cards, no AI prompt.
        $decks = Deck::active()
            ->select(['id', 'deck_code', 'title', 'segment', 'intensity_level', 'core_theme_keywords', 'card_front_bg_url', 'card_back_bg_url'])
            ->with(['categories' => fn ($q) => $q->active()->select('deck_categories.id', 'name', 'slug')])
            ->when($filters['segment'] ?? null, fn ($q, $segment) => $q->where('segment', $segment))
            ->when($filters['level'] ?? null, fn ($q, $level) => $q->where('segment', DeckSegment::fromSlug($level)->value))
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('categories', fn ($c) => $c->where('slug', $slug)->where('is_active', true)))
            ->when($filters['intensity_level'] ?? null, fn ($q, $level) => $q->where('intensity_level', $level))
            ->addSelect([
                // Lowest price of an active product that unlocks this deck ("From $19").
                'from_price_cents' => DB::table('products')
                    ->join('product_deck_claims', 'product_deck_claims.product_id', '=', 'products.id')
                    ->whereColumn('product_deck_claims.deck_id', 'decks.id')
                    ->where('products.is_active', true)
                    ->selectRaw('MIN(COALESCE(products.sale_price_cents, products.price_cents))'),
                // Copies sold: access granted through paid orders.
                'sales_count' => DB::table('user_entitlements')
                    ->whereColumn('user_entitlements.deck_id', 'decks.id')
                    ->whereNotNull('order_id')
                    ->selectRaw('COUNT(*)'),
            ])
            ->tap(fn ($q) => match ($filters['sort'] ?? 'featured') {
                'bestsellers' => $q->orderByDesc('sales_count')->orderBy('deck_code'),
                'newest' => $q->orderByDesc('decks.created_at')->orderBy('deck_code'),
                'price_asc' => $q->orderByRaw('from_price_cents ASC NULLS LAST')->orderBy('deck_code'),
                'price_desc' => $q->orderByRaw('from_price_cents DESC NULLS LAST')->orderBy('deck_code'),
                default => $q->orderBy('deck_code'),
            })
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($decks, DeckResource::class, 'Decks retrieved.');
    }

    public function show(
        Request $request,
        string $deckCode,
        VaultService $vault,
        SeoMetadataService $seo,
        SchemaGeneratorService $schema,
        DeckEditions $editions,
    ): JsonResponse {
        $deck = Deck::active()->where('deck_code', strtoupper($deckCode))
            ->with(['categories' => fn ($q) => $q->active()])
            ->firstOrFail();
        $user = $request->user('sanctum');

        $entitled = $user && ($user->isStaff() || $user->hasAccessToDeck($deck));
        $lockReason = match (true) {
            ! $entitled => self::LOCK_NOT_ENTITLED,
            $vault->blocksDeck($user, $deck) => self::LOCK_VAULT,
            default => null,
        };
        $isLocked = $lockReason !== null;

        // Locked: the public preview (card 1) only. The full deck is never serialised.
        $cards = $deck->cards()
            ->when($isLocked, fn ($q) => $q->where('card_number', 1))
            ->get();

        $totalCards = $deck->cards()->count();
        $products = $deck->products()->active()->with('decks:id')->get();
        ['digital' => $digital, 'physical' => $physical] = $editions->editionsFor($deck);
        $owned = $editions->ownership($user, $deck);
        $credit = $physical ? min($owned['digital_credit_cents'], $physical->effectivePriceCents()) : 0;

        return ApiResponse::success([
            'deck' => new DeckResource($deck),
            'is_locked' => $isLocked,
            'lock_reason' => $lockReason,
            'has_entitlement' => $entitled,
            'total_cards' => $totalCards,
            // Digital vs physical edition, and the viewer's position (all false/0 for guests).
            'editions' => [
                'digital' => $digital ? [
                    'product_slug' => $digital->slug,
                    'product_id' => $digital->id,
                    'price_cents' => $digital->effectivePriceCents(),
                    'product' => new ProductResource($digital),
                ] : null,
                'physical' => $physical ? [
                    'product_slug' => $physical->slug,
                    'product_id' => $physical->id,
                    'price_cents' => $physical->effectivePriceCents(),
                    'requires_shipping' => $physical->requiresShipping(),
                    'in_stock' => $physical->hasStockFor(1),
                    'product' => new ProductResource($physical),
                ] : null,
                'user_owns_digital' => $owned['owns_digital'],
                'user_owns_physical' => $owned['owns_physical'],
                'is_eligible_for_upgrade_discount' => $credit > 0,
                'upgrade_discount_cents' => $credit,
                'final_physical_price_cents' => $physical ? $physical->effectivePriceCents() - $credit : null,
            ],
            'cards' => CardResource::collection($cards),
            'seo' => $seo->forDeck($deck),
            // Built from the public preview card only, regardless of the viewer's access.
            'schema_json_ld' => $schema->forDeck($deck, $cards->firstWhere('card_number', 1), $totalCards, $products),
        ], $isLocked ? 'Deck preview.' : 'Deck unlocked.');
    }
}
