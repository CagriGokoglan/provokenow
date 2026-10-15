<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeckCategoryResource\Pages;
use App\Filament\Support\TaxonomyResource;
use App\Models\DeckCategory;

class DeckCategoryResource extends TaxonomyResource
{
    protected static ?string $model = DeckCategory::class;

    protected static string $countRelation = 'decks';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Deck Categories';

    protected static ?int $navigationSort = 3;

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDeckCategories::route('/')];
    }
}
