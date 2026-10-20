<?php

namespace App\Http\Resources;

use App\Models\UserEntitlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserEntitlement */
class EntitlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'order_id' => $this->order_id,
            'acquired_via' => $this->acquired_via,
            // Gifted decks show who sent them, their note and when they were claimed.
            'gift' => $this->whenLoaded('gift', fn () => $this->gift ? [
                'from' => $this->gift->fromName(),
                'message' => $this->gift->gift_message,
                'claimed_at' => $this->gift->claimed_at?->toIso8601String(),
            ] : null),
            'deck' => new DeckResource($this->whenLoaded('deck')),
        ];
    }
}
