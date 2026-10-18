<?php

namespace App\Filament\Resources\PostCategoryResource\Pages;

use App\Filament\Resources\PostCategoryResource;
use App\Filament\Support\FlushesFrontendOnReorder;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManagePostCategories extends ManageRecords
{
    use FlushesFrontendOnReorder;

    protected static string $resource = PostCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
