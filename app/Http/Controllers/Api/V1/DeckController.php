<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeckSegment;
use App\Http\Controllers\Controller;
use App\Http\Resources\CardResource;
use App\Http\Resources\DeckResource;
use App\Models\Deck;
use App\Services\Access\VaultService;
use App\Services\Seo\SchemaGeneratorService;
use App\Services\Seo\SeoMetadataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeckController extends Controller
{
    public const LOCK_NOT_ENTITLED = 'not_entitled';

    public const LOCK_VAULT = 'vault_locked';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'segment' => ['sometimes', Rule::enum(DeckSegment::class)],
            'intensity_level' => ['sometimes', 'integer', 'between:1,4'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        // Metadata columns only: no cards, no AI prompt.
        $decks = Deck::active()
            ->select(['id', 'deck_code', 'title', 'segment', 'intensity_level', 'core_theme_keywords', 'pod_card_box_theme', 'card_front_bg_url', 'card_back_bg_url'])
            ->when($filters['segment'] ?? null, fn ($q, $segment) => $q->where('segment', $segment))
            ->when($filters['intensity_level'] ?? null, fn ($q, $level) => $q->where('intensity_level', $level))
            ->orderBy('deck_code')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($decks, DeckResource::class, 'Decks retrieved.');
    }

    public function show(
        Request $request,
        string $deckCode,
        VaultService $vault,
        SeoMetadataService $seo,
        SchemaGeneratorService $schema,
    ): JsonResponse {
        $deck = Deck::active()->where('deck_code', strtoupper($deckCode))->firstOrFail();
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

        return ApiResponse::success([
            'deck' => new DeckResource($deck),
            'is_locked' => $isLocked,
            'lock_reason' => $lockReason,
            'has_entitlement' => $entitled,
            'total_cards' => $totalCards,
            'cards' => CardResource::collection($cards),
            'seo' => $seo->forDeck($deck),
            // Built from the public preview card only, regardless of the viewer's access.
            'schema_json_ld' => $schema->forDeck($deck, $cards->firstWhere('card_number', 1), $totalCards, $products),
        ], $isLocked ? 'Deck preview.' : 'Deck unlocked.');
    }
}
