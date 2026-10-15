<?php

namespace App\Models;

use App\Models\Concerns\FlushesFrontendCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Admin-managed fantasy / content tag for decks (BDSM, Roleplay, Sensory, …). */
#[Fillable(['name', 'slug', 'description', 'sort_order', 'is_active'])]
class DeckCategory extends Model
{
    use FlushesFrontendCache, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function decks(): BelongsToMany
    {
        return $this->belongsToMany(Deck::class, 'deck_category_deck');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
