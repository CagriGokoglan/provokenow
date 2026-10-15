<?php

namespace App\Filament\Resources\DeckCategoryResource\Pages;

use App\Filament\Resources\DeckCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageDeckCategories extends ManageRecords
{
    protected static string $resource = DeckCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
