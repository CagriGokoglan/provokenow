<?php

namespace App\Filament\Resources\DeckResource\Pages;

use App\Filament\Resources\DeckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDecks extends ListRecords
{
    protected static string $resource = DeckResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
