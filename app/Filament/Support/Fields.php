<?php

namespace App\Filament\Support;

use App\Services\Seo\SeoMetadataService;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/** Form building blocks shared by several resources. */
final class Fields
{
    /** Edited as a decimal amount, stored as integer cents. */
    public static function money(string $name): TextInput
    {
        $currency = config('provoke.commerce.currency');

        return TextInput::make($name)
            ->numeric()
            ->inputMode('decimal')
            ->step(0.01)
            ->minValue(0)
            ->prefix($currency === 'USD' ? '$' : $currency)
            ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round(((float) $state) * 100));
    }

    /** Character counter that turns into a warning when over the limit. */
    public static function counter(int $max): \Closure
    {
        return fn (?string $state) => sprintf('%d / %d characters', mb_strlen((string) $state), $max);
    }

    /**
     * Meta title / description / canonical / OG image. Leave blank to use generated defaults.
     *
     * @param  list<Component>  $extra
     */
    public static function seoTab(string $uploadDirectory, array $extra = []): Tab
    {
        return Tab::make('SEO & GEO')
            ->icon('heroicon-o-magnifying-glass')
            ->schema([
                TextInput::make('meta_title')
                    ->maxLength(SeoMetadataService::TITLE_MAX)
                    ->live(debounce: 300)
                    ->helperText(self::counter(SeoMetadataService::TITLE_MAX))
                    ->placeholder('Generated from the title when empty'),
                Textarea::make('meta_description')
                    ->rows(3)
                    ->maxLength(SeoMetadataService::DESCRIPTION_MAX)
                    ->live(debounce: 300)
                    ->helperText(self::counter(SeoMetadataService::DESCRIPTION_MAX))
                    ->placeholder('Generated when empty'),
                TextInput::make('canonical_url')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('Defaults to the public page URL on '.config('provoke.frontend.url')),
                FileUpload::make('og_image')
                    ->label('Social share image (Open Graph)')
                    ->image()
                    ->disk('public')
                    ->directory($uploadDirectory)
                    ->visibility('public')
                    ->imageEditor()
                    ->maxSize(4096)
                    ->helperText('Recommended 1200 × 630.'),
                ...$extra,
            ]);
    }
}
