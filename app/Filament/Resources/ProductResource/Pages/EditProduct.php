<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sold products are referenced by orders (FK restrict); deactivate them instead.
            Actions\DeleteAction::make()->visible(fn () => ! $this->getRecord()->orderItems()->exists()),
        ];
    }

    /**
     * The form only manages some metadata keys (images, upsell, requires_shipping), and hidden
     * sections are not submitted. Merge at the top level so every other key survives a save,
     * while managed keys are replaced whole (so removing an image actually removes it).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['metadata'] = [...($this->getRecord()->metadata ?? []), ...($data['metadata'] ?? [])];

        return $data;
    }
}
