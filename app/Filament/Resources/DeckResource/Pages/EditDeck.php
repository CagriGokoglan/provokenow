<?php

namespace App\Filament\Resources\DeckResource\Pages;

use App\Filament\Resources\DeckResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeck extends EditRecord
{
    protected static string $resource = DeckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Deleting a deck cascades to cards and customer entitlements, so only allow it
            // for superadmins and only before anyone owns it. Deactivate instead.
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()->isSuperAdmin() && ! $this->getRecord()->entitlements()->exists()),
        ];
    }
}
