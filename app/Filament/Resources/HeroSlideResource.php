<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HeroSlideResource\Pages;
use App\Models\Deck;
use App\Models\HeroSlide;
use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Homepage hero slider: full-width image or video, copy and a CTA to a deck, product or URL. */
class HeroSlideResource extends Resource
{
    protected static ?string $model = HeroSlide::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Homepage';

    protected static ?string $navigationLabel = 'Hero Slider';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Section::make('Slide')->columnSpan(2)->schema([
                TextInput::make('eyebrow')->label('Eyebrow / type')->maxLength(80)->placeholder('Digital Decks'),
                TextInput::make('title')->required()->maxLength(120),
                TextInput::make('subtitle')->maxLength(255),
                Grid::make(2)->schema([
                    FileUpload::make('image')
                        ->label('Image (desktop)')
                        ->image()
                        ->disk('public')->directory('home/hero')->visibility('public')
                        ->maxSize(6144)
                        ->imageEditor()
                        ->helperText('Wide, at least 2400 × 1200.'),
                    FileUpload::make('image_mobile')
                        ->label('Image (mobile, optional)')
                        ->image()
                        ->disk('public')->directory('home/hero')->visibility('public')
                        ->maxSize(4096)
                        ->imageEditor()
                        ->helperText('Portrait, around 1080 × 1600.'),
                ]),
                TextInput::make('video_url')
                    ->label('Video URL (optional)')
                    ->placeholder('https://cdn.example.com/hero.mp4')
                    ->maxLength(255)
                    ->regex('#^(/[^\s]*|https://[^\s]+)$#')
                    ->validationMessages(['regex' => 'Use a full https:// link to an .mp4 or .webm file.'])
                    ->helperText('Plays muted on a loop; the image is shown while it loads. With no image or video the slide uses the brand backdrop.'),
            ]),
            Section::make('Call to action')->columnSpan(1)->schema([
                ToggleButtons::make('target_type')
                    ->label('Links to')
                    ->options([HeroSlide::TARGET_DECK => 'Deck', HeroSlide::TARGET_PRODUCT => 'Product', HeroSlide::TARGET_CUSTOM => 'URL'])
                    ->default(HeroSlide::TARGET_DECK)
                    ->inline()
                    ->live()
                    ->required(),
                Select::make('deck_id')
                    ->label('Deck')
                    ->options(fn () => Deck::orderBy('deck_code')->get()->mapWithKeys(fn (Deck $d) => [$d->id => "{$d->deck_code} — {$d->title}"]))
                    ->searchable()
                    ->visible(fn (Get $get) => $get('target_type') === HeroSlide::TARGET_DECK)
                    ->required(fn (Get $get) => $get('target_type') === HeroSlide::TARGET_DECK),
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (Get $get) => $get('target_type') === HeroSlide::TARGET_PRODUCT)
                    ->required(fn (Get $get) => $get('target_type') === HeroSlide::TARGET_PRODUCT),
                TextInput::make('custom_url')
                    ->label('URL')
                    ->placeholder('/store?category=heels')
                    ->maxLength(255)
                    ->regex('#^(/[^\s]*|https://[^\s]+)$#')
                    ->validationMessages(['regex' => 'Use a site path like /store or a full https:// link.'])
                    ->visible(fn (Get $get) => $get('target_type') === HeroSlide::TARGET_CUSTOM)
                    ->required(fn (Get $get) => $get('target_type') === HeroSlide::TARGET_CUSTOM),
                TextInput::make('cta_label')
                    ->label('Button text')
                    ->maxLength(40)
                    ->placeholder(fn (Get $get) => match ($get('target_type')) {
                        HeroSlide::TARGET_DECK => 'Explore Deck',
                        HeroSlide::TARGET_PRODUCT => 'Shop Now',
                        default => 'Discover',
                    }),
                Toggle::make('is_active')->label('Live')->default(true),
                DateTimePicker::make('starts_at')->label('Show from')->native(false),
                DateTimePicker::make('ends_at')->label('Show until')->native(false)->after('starts_at'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->color('gray'),
                Tables\Columns\ImageColumn::make('image')->disk('public')->height(48)->width(96),
                Tables\Columns\IconColumn::make('video_url')->label('Video')->boolean()->state(fn (HeroSlide $r) => filled($r->video_url)),
                Tables\Columns\TextColumn::make('title')->weight('medium')->description(fn (HeroSlide $r) => $r->eyebrow),
                Tables\Columns\TextColumn::make('target_type')->label('Links to')->badge()
                    ->formatStateUsing(fn (string $state, HeroSlide $r) => match ($state) {
                        HeroSlide::TARGET_DECK => 'Deck · '.($r->deck?->deck_code ?? 'missing'),
                        HeroSlide::TARGET_PRODUCT => 'Product · '.($r->product?->name ?? 'missing'),
                        default => $r->custom_url,
                    }),
                Tables\Columns\TextColumn::make('schedule')
                    ->state(fn (HeroSlide $r) => $r->starts_at || $r->ends_at
                        ? ($r->starts_at?->format('M j') ?? '…').' → '.($r->ends_at?->format('M j') ?? '…')
                        : 'Always')
                    ->color('gray'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Live'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHeroSlides::route('/'),
            'create' => Pages\CreateHeroSlide::route('/create'),
            'edit' => Pages\EditHeroSlide::route('/{record}/edit'),
        ];
    }
}
