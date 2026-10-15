<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeckSegment;
use App\Http\Controllers\Controller;
use App\Models\DeckCategory;
use App\Models\ProductCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Filter vocabularies for /decks and /store, in display order. */
class TaxonomyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tag = fn ($c) => ['name' => $c->name, 'slug' => $c->slug, 'description' => $c->description];

        return ApiResponse::success([
            'deck_levels' => array_map(fn (DeckSegment $s) => ['slug' => $s->slug(), 'label' => $s->label(), 'rank' => $s->rank()], DeckSegment::cases()),
            'deck_categories' => DeckCategory::active()->get()->map($tag)->values(),
            'product_categories' => ProductCategory::active()->get()->map($tag)->values(),
        ], 'Taxonomies.')->header('Cache-Control', 'public, max-age=300');
    }
}
