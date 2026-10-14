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
            'deck' => new DeckResource($this->whenLoaded('deck')),
        ];
    }
}
