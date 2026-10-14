<?php

namespace App\Filament\Resources;

use App\Enums\DeckSegment;
use App\Enums\ProductType;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Support\Fields;
use App\Models\Deck;
use App\Models\Product;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
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
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Product')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Product')->icon('heroicon-o-shopping-bag')->columns(2)->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get, string $operation) {
                            if ($operation === 'create' && blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->prefix('/shop/'),
                    TextInput::make('sku')->label('SKU')->required()->maxLength(64)->unique(ignoreRecord: true),
                    Select::make('product_type')->options(ProductType::class)->required()->native(false)->live(),
                    Textarea::make('description')->rows(5)->columnSpanFull(),
                    Toggle::make('is_active')->label('Active (purchasable)')->default(true),
                    Toggle::make('metadata.requires_shipping')
                        ->label('Requires shipping')
                        ->helperText('Bundles only: whether this bundle contains physical items.')
                        ->default(true)
                        ->visible(fn (Get $get) => $get('product_type') === ProductType::Bundle->value || $get('product_type') === ProductType::Bundle),
                ]),

                Tab::make('Pricing & Stock')->icon('heroicon-o-banknotes')->columns(3)->schema([
                    Fields::money('price_cents')->label('Price')->required(),
                    Fields::money('sale_price_cents')->label('Sale price')->lte('price_cents')
                        ->helperText('Optional. Must not exceed the price.'),
                    TextInput::make('stock_quantity')
                        ->numeric()
                        ->integer()
                        ->required()
                        ->minValue(Product::UNLIMITED_STOCK)
                        ->default(Product::UNLIMITED_STOCK)
                        ->helperText('-1 = unlimited (digital and print-on-demand).'),
                ]),

                Tab::make('Images')->icon('heroicon-o-photo')->schema([
                    FileUpload::make('metadata.images')
                        ->hiddenLabel()
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->disk('public')
                        ->directory('products')
                        ->visibility('public')
                        ->maxFiles(10)
                        ->maxSize(4096)
                        ->imageEditor()
                        ->panelLayout('grid')
                        ->helperText('The first image is the primary product image.'),
                ]),

                Tab::make('Deck Access')->icon('heroicon-o-key')->schema([
                    Select::make('decks')
                        ->label('Decks unlocked on purchase')
                        ->relationship('decks', 'deck_code')
                        ->getOptionLabelFromRecordUsing(fn (Deck $deck) => "{$deck->deck_code} — {$deck->title}")
                        ->multiple()
                        ->searchable(['deck_code', 'title'])
                        ->preload()
                        ->helperText('Customers get digital access to these decks as soon as payment is confirmed. Bundles can claim several.'),
                ]),

                Tab::make('Upsells')->icon('heroicon-o-arrow-trending-up')->schema([
                    Section::make('Curated upsells')
                        ->description('Offered when this product is in the cart, at the discount you set here. Takes priority over the automatic rules.')
                        ->schema([
                            Repeater::make('upsellRules')
                                ->hiddenLabel()
                                ->relationship()
                                ->orderColumn('sort_order')
                                ->columns(3)
                                ->defaultItems(0)
                                ->addActionLabel('Add upsell product')
                                ->schema([
                                    Select::make('upsell_product_id')
                                        ->label('Offer product')
                                        ->options(fn (?Product $record) => Product::query()
                                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                            ->orderBy('name')
                                            ->get()
                                            ->mapWithKeys(fn (Product $p) => [$p->id => "{$p->name} ({$p->sku})"]))
                                        ->searchable()
                                        ->required()
                                        ->distinct()
                                        ->columnSpan(2),
                                    TextInput::make('discount_percent')
                                        ->label('Bundle discount')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->maxValue(90)
                                        ->default((int) config('provoke.upsell.bundle_discount_percent'))
                                        ->suffix('%')
                                        ->required(),
                                ]),
                        ]),
                    Section::make('Automatic pairing (accessories)')
                        ->description('Digital decks matching these rules automatically offer this accessory at the default '.config('provoke.upsell.bundle_discount_percent').'% bundle discount. POD boxes are paired with their digital deck automatically.')
                        ->visible(fn (Get $get) => in_array($get('product_type'), [ProductType::PhysicalAccessory->value, ProductType::PhysicalAccessory], true))
                        ->schema([
                            CheckboxList::make('metadata.upsell.segments')->label('Pair with decks in segments')->options(DeckSegment::class)->columns(3),
                            Select::make('metadata.upsell.deck_codes')
                                ->label('…or with these specific decks')
                                ->multiple()
                                ->options(fn () => Deck::orderBy('deck_code')->pluck('deck_code', 'deck_code'))
                                ->searchable(),
                            Toggle::make('metadata.upsell.all_digital_decks')->label('Pair with every digital deck'),
                        ]),
                ]),

                Fields::seoTab('seo/products'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $currency = config('provoke.commerce.currency');

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('primary_image')
                    ->label('')
                    ->state(fn (Product $record) => $record->images()[0] ?? null)
                    ->square()
                    ->size(40),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('medium')
                    ->description(fn (Product $record) => $record->sku),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('product_type')->label('Type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('price_cents')->label('Price')->money($currency, divideBy: 100)->sortable(),
                Tables\Columns\TextColumn::make('sale_price_cents')->label('Sale')->money($currency, divideBy: 100)->placeholder('—'),
                Tables\Columns\TextColumn::make('stock_quantity')->label('Stock')->alignCenter()
                    ->formatStateUsing(fn (int $state) => $state === Product::UNLIMITED_STOCK ? '∞' : (string) $state)
                    ->color(fn (int $state) => match (true) {
                        $state === Product::UNLIMITED_STOCK => 'gray',
                        $state === 0 => 'danger',
                        $state < 5 => 'warning',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('decks_count')->counts('decks')->label('Decks')->alignCenter(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type')->options(ProductType::class),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku', 'slug'];
    }
}
