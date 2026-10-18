<?php

namespace App\Models;

use App\Models\Concerns\FlushesFrontendCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A legal / compliance page at /legal/{slug} (terms, privacy, refunds, shipping, contact, 18+). */
#[Fillable(['title', 'slug', 'content', 'seo_title', 'seo_description', 'is_published', 'sort_order'])]
class LegalPage extends Model
{
    use FlushesFrontendCache, HasUuids;

    /** The page that also shows the contact form. */
    public const CONTACT_SLUG = 'contact';

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }
}
