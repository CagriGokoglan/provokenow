<?php

namespace App\Filament\Resources\InstagramPostResource\Pages;

use App\Filament\Resources\InstagramPostResource;
use App\Filament\Support\FlushesFrontendOnReorder;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageInstagramPosts extends ManageRecords
{
    use FlushesFrontendOnReorder;

    protected static string $resource = InstagramPostResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
