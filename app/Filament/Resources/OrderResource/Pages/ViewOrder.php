<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::markPaidAction(Action::make('markPaid'))
                ->after(fn () => $this->record = $this->resolveRecord($this->record->getKey())),
            // Reload afterwards so the page shows the new status/tracking with its relations intact.
            OrderResource::markShippedAction()->after(fn () => $this->record = $this->resolveRecord($this->record->getKey())),
            OrderResource::updateFulfillmentAction()->after(fn () => $this->record = $this->resolveRecord($this->record->getKey())),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('items.order', 'entitlements.deck', 'user');
    }
}
