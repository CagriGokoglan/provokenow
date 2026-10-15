<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Provoke Club waitlist entry. */
#[Fillable(['email', 'user_id', 'source', 'consented_at'])]
class ClubSignup extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['consented_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
