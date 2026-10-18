<?php

namespace App\Models;

use App\Models\Concerns\FlushesFrontendCache;
use App\Support\LineBreaks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * content_payload is free-form JSON, typically keyed by the deck's column schema, e.g.
 * Card_ID, Title, Setting_and_Scenario, Action_Task, Safety_and_Consent_Note.
 */
#[Fillable(['deck_id', 'card_number', 'is_preview', 'title', 'content_payload'])]
class Card extends Model
{
    use FlushesFrontendCache, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'card_number' => 'integer',
            'is_preview' => 'boolean',
            'content_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Literal "\n" from pasted / double-encoded JSON becomes a real line break.
        static::saving(function (Card $card) {
            if ($card->isDirty('content_payload')) {
                $card->content_payload = LineBreaks::fixAll($card->content_payload);
            }

            // At most two hand-picked preview cards per deck.
            if ($card->is_preview && $card->isDirty('is_preview') && static::otherPreviewCount($card) >= Deck::previewLimit()) {
                throw ValidationException::withMessages([
                    'is_preview' => 'This deck already has '.Deck::previewLimit().' preview cards. Untick one of them first.',
                ]);
            }
        });
    }

    /** Preview cards in the same deck, not counting this one. */
    public static function otherPreviewCount(Card $card): int
    {
        return static::where('deck_id', $card->deck_id)->where('is_preview', true)->whereKeyNot($card->getKey())->count();
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }
}
