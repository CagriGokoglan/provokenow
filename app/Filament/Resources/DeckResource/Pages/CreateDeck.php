<?php

namespace App\Filament\Resources\DeckResource\Pages;

use App\Filament\Resources\DeckResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeck extends CreateRecord
{
    protected static string $resource = DeckResource::class;

    protected function getRedirectUrl(): string
    {
        // Straight to the edit page so cards can be imported.
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
