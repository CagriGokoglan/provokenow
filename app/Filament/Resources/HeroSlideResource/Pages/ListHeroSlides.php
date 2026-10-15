<?php

namespace App\Filament\Resources\HeroSlideResource\Pages;

use App\Filament\Resources\HeroSlideResource;
use App\Filament\Support\FlushesFrontendOnReorder;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHeroSlides extends ListRecords
{
    use FlushesFrontendOnReorder;

    protected static string $resource = HeroSlideResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
