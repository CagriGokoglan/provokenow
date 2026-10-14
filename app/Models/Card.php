<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * content_payload is free-form JSON, typically keyed by the deck's column schema, e.g.
 * Card_ID, Title, Setting_and_Scenario, Action_Task, Safety_and_Consent_Note.
 */
#[Fillable(['deck_id', 'card_number', 'title', 'content_payload'])]
class Card extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'card_number' => 'integer',
            'content_payload' => 'array',
        ];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }
}
