<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstagramPostResource\Pages;
use App\Models\InstagramPost;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Curated Instagram posts for the homepage (the first three live ones are shown). We store the
 * post link plus a cover image: Instagram images can't be hotlinked, and its embed script sets
 * Meta cookies before consent.
 */
class InstagramPostResource extends Resource
{
    protected static ?string $model = InstagramPost::class;

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Homepage';

    protected static ?string $navigationLabel = 'Instagram Feed';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            TextInput::make('post_url')
                ->label('Instagram post URL')
                ->required()
                ->url()
                ->regex('#^https://(www\.)?instagram\.com/(p|reel)/[A-Za-z0-9_-]+/?#')
                ->validationMessages(['regex' => 'Paste a post or reel link, e.g. https://www.instagram.com/p/ABC123/'])
                ->placeholder('https://www.instagram.com/p/…/'),
            FileUpload::make('image')
                ->label('Cover image')
                ->required()
                ->image()
                ->imageCropAspectRatio('1:1')
                ->imageEditor()
                ->disk('public')->directory('home/instagram')->visibility('public')
                ->maxSize(4096)
                ->helperText('Square, at least 1080 × 1080. Save the post image from Instagram and upload it here.'),
            TextInput::make('caption')->label('Alt text / caption')->maxLength(255),
            Toggle::make('is_active')->label('Show on homepage')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->description('The homepage shows exactly '.InstagramPost::HOMEPAGE_LIMIT.' posts side by side: the section appears once '.InstagramPost::HOMEPAGE_LIMIT.' are visible, and uses the first '.InstagramPost::HOMEPAGE_LIMIT.' in this order.')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->disk('public')->square()->size(56),
                Tables\Columns\TextColumn::make('post_url')->label('Post')->url(fn (InstagramPost $r) => $r->post_url, true)->limit(50),
                Tables\Columns\TextColumn::make('caption')->limit(40)->placeholder('—'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Visible'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageInstagramPosts::route('/')];
    }
}
