<?php

namespace App\Filament\Resources;

use App\Enums\DeckSegment;
use App\Filament\Resources\DeckResource\Pages;
use App\Filament\Resources\DeckResource\RelationManagers\CardsRelationManager;
use App\Filament\Support\Fields;
use App\Models\Deck;
use App\Models\Product;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class DeckResource extends Resource
{
    protected static ?string $model = Deck::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Deck')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Deck')->icon('heroicon-o-sparkles')->columns(2)->schema([
                    TextInput::make('deck_code')
                        ->required()
                        ->maxLength(20)
                        ->regex('/^DECK-\d{3,}$/i')
                        ->validationMessages(['regex' => 'Use the format DECK-001 (at least three digits, e.g. DECK-1000).'])
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (string $state) => strtoupper($state))
                        ->placeholder('DECK-001'),
                    TextInput::make('title')->required()->maxLength(255),
                    Select::make('segment')->options(DeckSegment::class)->required()->native(false),
                    Select::make('intensity_level')
                        ->options([1 => '1 · Gentle', 2 => '2 · Warm', 3 => '3 · Hot', 4 => '4 · Intense'])
                        ->required()
                        ->native(false),
                    Textarea::make('core_theme_keywords')
                        ->rows(2)
                        ->helperText('Comma-separated, e.g. "anticipation, trust, role play".')
                        ->columnSpanFull(),
                    Select::make('categories')
                        ->label('Fantasy categories')
                        ->relationship('categories', 'name', fn ($query) => $query->orderBy('sort_order'))
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText('Used by the category filter on /decks. Manage the list under Catalog → Deck Categories.')
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Active (visible in the catalogue)')
                        ->default(false),
                ]),
                Tab::make('Editions & Pricing')->icon('heroicon-o-banknotes')->columns(3)->schema([
                    Section::make('Digital edition')
                        ->description('Sold on the deck page and unlocked instantly in the buyer\'s library. Changes apply as soon as you save.')
                        ->columns(3)
                        ->schema([
                            Toggle::make('is_digital_sale_active')
                                ->label('Sell the digital deck')
                                ->live()
                                ->inline(false),
                            Fields::money('digital_price_cents')
                                ->label('Digital price')
                                ->required(fn (Get $get) => (bool) $get('is_digital_sale_active')),
                            TextInput::make('digital_sku')
                                ->label('Digital SKU')
                                ->maxLength(64)
                                ->placeholder(fn (Get $get) => 'DIG-'.strtoupper((string) ($get('deck_code') ?: 'DECK-001')))
                                ->unique(ignoreRecord: true)
                                ->rule(fn (?Deck $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                                    $clash = Product::where('sku', $value)
                                        ->when($record?->digitalProduct, fn ($q, $own) => $q->whereKeyNot($own->getKey()))
                                        ->exists();
                                    if (filled($value) && $clash) {
                                        $fail('A store product already uses this SKU.');
                                    }
                                }),
                        ]),
                    Section::make('Physical edition')
                        ->columns(1)
                        ->schema([
                            Placeholder::make('physical_edition')
                                ->hiddenLabel()
                                ->content(function (?Deck $record) {
                                    $box = $record?->physicalProduct;
                                    if (! $box) {
                                        return 'No physical box yet. Create one under Commerce → Products with type "Physical Boxed Game Set" and link this deck; buyers then get this deck digitally for free.';
                                    }
                                    $price = number_format($box->effectivePriceCents() / 100, 2).' '.config('provoke.commerce.currency');

                                    return new HtmlString(e("{$box->name} · {$price} · ".($box->is_active ? 'on sale' : 'inactive')).' — <a class="text-primary-600 underline" href="'.e(ProductResource::getUrl('edit', ['record' => $box])).'">edit the box</a>');
                                }),
                        ]),
                ]),
                Tab::make('Card Artwork')->icon('heroicon-o-paint-brush')->columns(2)->schema([
                    FileUpload::make('card_front_bg_url')
                        ->label('Card front background')
                        ->helperText('Behind the card text in the web app. SVG (sanitised), WebP or PNG; 5:7 portrait.')
                        ->acceptedFileTypes(['image/svg+xml', 'image/webp', 'image/png', 'image/jpeg'])
                        ->disk('public')->directory('decks/artwork')->visibility('public')->maxSize(2048),
                    FileUpload::make('card_back_bg_url')
                        ->label('Card back')
                        ->helperText('Shown before a card is revealed, and on locked cards.')
                        ->acceptedFileTypes(['image/svg+xml', 'image/webp', 'image/png', 'image/jpeg'])
                        ->disk('public')->directory('decks/artwork')->visibility('public')->maxSize(2048),
                ]),
                Fields::seoTab('seo/decks', [
                    Section::make('FAQ for answer engines')
                        ->description('Shown in FAQPage structured data. Never paste paid card content here.')
                        ->collapsible()
                        ->schema([
                            Repeater::make('seo_faq')
                                ->hiddenLabel()
                                ->schema([
                                    TextInput::make('question')->required()->maxLength(200),
                                    Textarea::make('answer')->required()->rows(3)->maxLength(1000),
                                ])
                                ->reorderable()
                                ->collapsible()
                                ->itemLabel(fn (array $state) => $state['question'] ?? null)
                                ->defaultItems(0)
                                ->addActionLabel('Add question'),
                        ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('deck_code')
            ->columns([
                Tables\Columns\TextColumn::make('deck_code')->label('Code')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('segment')->label('Level')->badge()->sortable(),
                Tables\Columns\TextColumn::make('categories.name')->label('Categories')->badge()->color('gray')->limitList(3)->toggleable(),
                Tables\Columns\TextColumn::make('intensity_level')->label('Intensity')->sortable()->alignCenter()
                    ->formatStateUsing(fn (int $state) => str_repeat('●', $state).str_repeat('○', 4 - $state)),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('cards_count')->counts('cards')->label('Cards')->alignCenter()
                    ->color(fn (int $state) => $state === 50 ? 'success' : 'warning')
                    ->formatStateUsing(fn (int $state) => "{$state} / 50"),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('segment')->label('Level')->options(DeckSegment::class),
                Tables\Filters\SelectFilter::make('categories')->relationship('categories', 'name')->multiple()->preload(),
                Tables\Filters\SelectFilter::make('intensity_level')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4']),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('activate')
                    ->icon('heroicon-o-eye')
                    ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('deactivate')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getRelations(): array
    {
        return [CardsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDecks::route('/'),
            'create' => Pages\CreateDeck::route('/create'),
            'edit' => Pages\EditDeck::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['deck_code', 'title'];
    }
}
