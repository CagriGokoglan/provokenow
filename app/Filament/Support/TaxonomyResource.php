<?php

namespace App\Filament\Support;

use App\Models\Product;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Shared form/table for simple name + slug category lists (deck & store categories). */
abstract class TaxonomyResource extends Resource
{
    /** Relationship counted in the table ("decks" / "products"). */
    protected static string $countRelation;

    /** Store categories can be listed in the footer's Explore column. */
    protected static bool $footerToggle = false;

    /** Store categories live at /store/{slug}, the same path space as products. */
    protected static bool $storePath = false;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(80)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, Set $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')
                ->required()
                ->maxLength(80)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                    if (static::$storePath && Product::where('slug', $value)->exists()) {
                        $fail('A product already uses this slug, so /store/'.$value.' would be ambiguous. Choose another.');
                    }
                })
                ->helperText(static::$storePath ? 'Used in the URL, e.g. /store/heels' : 'Used in URLs, e.g. /decks?category=roleplay'),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label('Visible on the site')->default(true)->inline(false),
            Toggle::make('show_in_footer')->label('Show in footer (Explore)')->inline(false)->visible(static::$footerToggle),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->weight('medium'),
                Tables\Columns\TextColumn::make('slug')->color('gray'),
                Tables\Columns\TextColumn::make(static::$countRelation.'_count')->counts(static::$countRelation)->label(Str::headline(static::$countRelation))->alignCenter(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Visible'),
                Tables\Columns\ToggleColumn::make('show_in_footer')->label('In footer')->visible(static::$footerToggle),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
