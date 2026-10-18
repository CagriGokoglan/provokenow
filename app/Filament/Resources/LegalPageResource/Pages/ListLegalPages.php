<?php

namespace App\Filament\Resources\LegalPageResource\Pages;

use App\Filament\Resources\LegalPageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLegalPages extends ListRecords
{
    protected static string $resource = LegalPageResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
