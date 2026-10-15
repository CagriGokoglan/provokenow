<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// The site is English-only: preferred_language keeps its 'en' default and is not editable.
#[Fillable(['marketing_opt_in'])]
#[Hidden(['digital_vault_passcode_hash'])]
class UserProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'preferred_language' => 'en',
        'marketing_opt_in' => false,
    ];

    protected function casts(): array
    {
        return [
            'marketing_opt_in' => 'boolean',
            'digital_vault_passcode_hash' => 'hashed',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasVaultPasscode(): bool
    {
        return $this->digital_vault_passcode_hash !== null;
    }
}
