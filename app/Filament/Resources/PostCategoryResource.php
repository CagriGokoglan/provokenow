<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostCategoryResource\Pages;
use App\Filament\Support\TaxonomyResource;
use App\Models\PostCategory;

class PostCategoryResource extends TaxonomyResource
{
    protected static ?string $model = PostCategory::class;

    protected static string $countRelation = 'posts';

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Journal';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'journal category';

    protected static ?int $navigationSort = 2;

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePostCategories::route('/')];
    }
}
