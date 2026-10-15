<?php

namespace App\Filament\Resources;

use App\Enums\DeckSegment;
use App\Filament\Resources\DeckResource\Pages;
use App\Filament\Resources\DeckResource\RelationManagers\CardsRelationManager;
use App\Filament\Support\Fields;
use App\Models\Deck;
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
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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
                    Toggle::make('is_active')
                        ->label('Active (visible in the catalogue)')
                        ->default(false),
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
                Tab::make('AI & Production')->icon('heroicon-o-cpu-chip')->schema([
                    Textarea::make('ai_system_role_prompt')
                        ->label('AI system role prompt')
                        ->rows(12)
                        ->helperText('Internal only — never returned by the public API.'),
                    TextInput::make('pod_card_box_theme')->label('POD box theme')->maxLength(255),
                    TextInput::make('digital_delivery_file_name')->maxLength(255)->placeholder('DECK-001.pdf'),
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
                Tables\Columns\TextColumn::make('segment')->badge()->sortable(),
                Tables\Columns\TextColumn::make('intensity_level')->label('Intensity')->sortable()->alignCenter()
                    ->formatStateUsing(fn (int $state) => str_repeat('●', $state).str_repeat('○', 4 - $state)),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('cards_count')->counts('cards')->label('Cards')->alignCenter()
                    ->color(fn (int $state) => $state === 50 ? 'success' : 'warning')
                    ->formatStateUsing(fn (int $state) => "{$state} / 50"),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('segment')->options(DeckSegment::class),
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
