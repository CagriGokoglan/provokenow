<?php

namespace App\Filament\Resources;

use App\Enums\ProductType;
use App\Filament\Resources\PostResource\Pages;
use App\Filament\Support\Fields;
use App\Models\Deck;
use App\Models\Post;
use App\Services\Seo\SeoMetadataService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
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

/** The Journal (/blog): pillar posts and their topic clusters, with product CTAs and author boxes. */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Journal';

    protected static ?string $navigationLabel = 'Posts';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Post')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tab::make('Content')->icon('heroicon-o-pencil-square')->columns(2)->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get, string $operation) {
                            if ($operation === 'create' && blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('slug')->required()->maxLength(255)->alphaDash()->unique(ignoreRecord: true)->prefix('/blog/'),
                    Select::make('post_category_id')->label('Category')->relationship('category', 'name')->preload()->searchable(),
                    Toggle::make('is_pillar')
                        ->label('Pillar post')
                        ->helperText('The long, central guide of a topic cluster.')
                        ->live(),
                    Select::make('pillar_post_id')
                        ->label('Part of the cluster of')
                        ->relationship('pillar', 'title', fn ($query, ?Post $record) => $query->where('is_pillar', true)->when($record, fn ($q) => $q->whereKeyNot($record->getKey())))
                        ->searchable()
                        ->preload()
                        ->hidden(fn (Get $get) => (bool) $get('is_pillar'))
                        ->helperText('Links this post to its pillar and lists it on the pillar page.'),
                    Textarea::make('excerpt')->rows(2)->maxLength(300)->helperText('Shown on the Journal index and in previews.')->columnSpanFull(),
                    Textarea::make('tldr')->label('TL;DR')->rows(3)->maxLength(600)->helperText('The short answer, shown at the top of the post.')->columnSpanFull(),
                    MarkdownEditor::make('content')
                        ->required()
                        ->columnSpanFull()
                        ->helperText('Use ## headings for sections: they become the jump links, and product cards are placed between them (about 30% and 80% through).'),
                    FileUpload::make('featured_image')
                        ->image()
                        ->disk('public')->directory('blog')->visibility('public')
                        ->maxSize(4096)
                        ->imageEditor()
                        ->columnSpanFull(),
                ]),
                Tab::make('Products')->icon('heroicon-o-shopping-bag')->schema([
                    Select::make('decks')
                        ->label('Decks to feature')
                        ->relationship('decks', 'deck_code')
                        ->getOptionLabelFromRecordUsing(fn (Deck $d) => "{$d->deck_code} — {$d->title}")
                        ->multiple()
                        ->searchable(['deck_code', 'title'])
                        ->preload(),
                    Select::make('products')
                        ->label('Store products to feature')
                        ->relationship('products', 'name', fn ($query) => $query->where('product_type', '!=', ProductType::DigitalDeck->value))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Shown as product cards about 30% and 80% through the post.'),
                ]),
                Tab::make('Author')->icon('heroicon-o-user')->columns(2)->schema([
                    TextInput::make('author_name')->maxLength(120)->placeholder('Dr. Jane Doe'),
                    TextInput::make('author_role')->maxLength(160)->placeholder('Licensed couples therapist'),
                    Textarea::make('author_bio')->rows(3)->maxLength(600)->columnSpanFull()
                        ->helperText('Real credentials build trust (E-E-A-T). Shown in the author box at the end.'),
                ]),
                Fields::faqTab('Shown at the end of the post and as FAQPage rich results.'),
                Tab::make('SEO & Publishing')->icon('heroicon-o-magnifying-glass')->columns(2)->schema([
                    TextInput::make('seo_title')
                        ->maxLength(SeoMetadataService::TITLE_MAX)
                        ->placeholder('Primary keyword first — generated from the title when empty')
                        ->columnSpanFull(),
                    Textarea::make('seo_description')
                        ->rows(2)
                        ->maxLength(SeoMetadataService::DESCRIPTION_MAX)
                        ->placeholder('140–155 characters — generated from the excerpt when empty')
                        ->columnSpanFull(),
                    Section::make()->columns(2)->schema([
                        Toggle::make('is_published')->label('Published')->inline(false),
                        DateTimePicker::make('published_at')->native(false)->helperText('A future time schedules the post.'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->weight('medium')->limit(60)
                    ->description(fn (Post $r) => $r->is_pillar ? 'Pillar' : ($r->pillar?->title ? 'Cluster of: '.Str::limit($r->pillar->title, 40) : null)),
                Tables\Columns\TextColumn::make('category.name')->badge()->color('gray')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_published')->label('Live')->boolean(),
                Tables\Columns\TextColumn::make('published_at')->dateTime('M j, Y')->sortable()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('post_category_id')->label('Category')->relationship('category', 'name'),
                Tables\Filters\TernaryFilter::make('is_pillar')->label('Pillar posts'),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
