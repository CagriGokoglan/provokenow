<?php

namespace App\Http\Resources;

use App\Models\Deck;
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
            'title' => $this->title,
            'segment' => $this->segment->value,
            'intensity_level' => $this->intensity_level,
            'core_theme' => $this->core_theme_keywords,
            'core_theme_keywords' => $this->themeKeywords(),
            'pod_card_box_theme' => $this->pod_card_box_theme,
            'vault_protected' => $this->isVaultProtected(),
        ];
    }
}
