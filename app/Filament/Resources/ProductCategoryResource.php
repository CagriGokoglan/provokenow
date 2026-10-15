<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductCategoryResource\Pages;
use App\Filament\Support\TaxonomyResource;
use App\Models\ProductCategory;

class ProductCategoryResource extends TaxonomyResource
{
    protected static ?string $model = ProductCategory::class;

    protected static string $countRelation = 'products';

    protected static bool $footerToggle = true;

    protected static bool $storePath = true;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Store Categories';

    protected static ?int $navigationSort = 4;

    public static function getPages(): array
    {
        return ['index' => Pages\ManageProductCategories::route('/')];
    }
}
