<?php

namespace App\Filament\Resources\LegalPageResource\Pages;

use App\Filament\Resources\LegalPageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLegalPage extends EditRecord
{
    protected static string $resource = LegalPageResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
