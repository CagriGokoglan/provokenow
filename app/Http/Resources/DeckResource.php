<?php

namespace App\Http\Resources;

use App\Models\Deck;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public deck metadata. Never includes card content or the AI system prompt.
 *
 * @mixin Deck
 */
class DeckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deck_code' => $this->deck_code,
            'slug' => $this->slug,
            'title' => $this->title,
            'segment' => $this->segment->value,
            'level' => ['slug' => $this->segment->slug(), 'label' => $this->segment->label(), 'rank' => $this->segment->rank()],
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug])->values()),
            'intensity_level' => $this->intensity_level,
            'core_theme' => $this->core_theme_keywords,
            'core_theme_keywords' => $this->themeKeywords(),
            'vault_protected' => $this->isVaultProtected(),
            // Public preview / sample cards: the admin's picks, else the first cards.
            'preview_cards' => $this->whenLoaded('previewCandidates', fn () => CardResource::collection($this->resource->previewCards())),
            // Dealt on the homepage only when an admin has picked its preview cards.
            'is_homepage_eligible' => $this->whenLoaded('previewCandidates', fn () => $this->resource->hasPickedPreview()),
            // Deck page sections (written in the admin; null = the web app's generated text).
            'page_content' => $this->when($request->routeIs('v1.decks.show'), fn () => [
                'whats_inside' => $this->whats_inside,
                'how_to_play' => $this->how_to_play,
                'who_its_for' => $this->who_its_for,
            ]),
            // Only present on the catalogue listing (computed there).
            'from_price_cents' => $this->when(array_key_exists('from_price_cents', $this->resource->getAttributes()), fn () => $this->resource->getAttributes()['from_price_cents'] === null ? null : (int) $this->resource->getAttributes()['from_price_cents']),
            'card_front_bg_url' => MediaUrl::resolve($this->card_front_bg_url),
            'card_back_bg_url' => MediaUrl::resolve($this->card_back_bg_url),
        ];
    }
}
