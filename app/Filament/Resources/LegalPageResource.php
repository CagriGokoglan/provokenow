<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LegalPageResource\Pages;
use App\Models\LegalPage;
use App\Services\Seo\SeoMetadataService;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Section;
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

/** Terms, privacy, refunds, shipping, contact and the 18+ notice (/legal/{slug}). */
class LegalPageResource extends Resource
{
    protected static ?string $model = LegalPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Legal & Support';

    protected static ?string $navigationLabel = 'Legal pages';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Page')->columns(2)->schema([
                TextInput::make('title')
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
                    ->prefix('/legal/')
                    ->helperText('The footer links to the six standard addresses; changing one of those breaks its footer link.'),
                MarkdownEditor::make('content')
                    ->required()
                    ->columnSpanFull()
                    ->helperText(fn (Get $get) => $get('slug') === LegalPage::CONTACT_SLUG
                        ? 'The contact form is shown below this text automatically.'
                        : 'Use ## for section headings. Links: [text](/legal/refund-policy) or [email](mailto:support@provokenow.com).'),
            ]),
            Section::make('Search & visibility')->columns(2)->schema([
                TextInput::make('seo_title')
                    ->maxLength(SeoMetadataService::TITLE_MAX)
                    ->placeholder('"Title | PROVOKE NOW" when empty')
                    ->columnSpanFull(),
                Textarea::make('seo_description')
                    ->rows(2)
                    ->maxLength(SeoMetadataService::DESCRIPTION_MAX)
                    ->placeholder('Taken from the start of the page when empty')
                    ->helperText('Legal pages are always hidden from search engines (noindex); this is for link previews.')
                    ->columnSpanFull(),
                Toggle::make('is_published')->label('Published')->default(true)->inline(false),
                TextInput::make('sort_order')->numeric()->default(0)->helperText('Lower comes first.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->weight('medium'),
                Tables\Columns\TextColumn::make('slug')->prefix('/legal/')->color('gray')->copyable(),
                Tables\Columns\IconColumn::make('is_published')->label('Live')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('Last updated')->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (LegalPage $p) => config('provoke.frontend.url').'/legal/'.$p->slug, shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalPages::route('/'),
            'create' => Pages\CreateLegalPage::route('/create'),
            'edit' => Pages\EditLegalPage::route('/{record}/edit'),
        ];
    }
}
