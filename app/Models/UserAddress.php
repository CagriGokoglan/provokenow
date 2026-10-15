<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Account address book entry (Account → Settings). */
#[Fillable(['label', 'name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'phone', 'is_default'])]
class UserAddress extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
