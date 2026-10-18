<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactMessageResource\Pages;
use App\Models\ContactMessage;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Messages from the contact form (also emailed to the support inbox). */
class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Legal & Support';

    protected static ?string $navigationLabel = 'Contact messages';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = ContactMessage::whereNull('handled_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('email')->copyable()->url(fn (ContactMessage $m) => 'mailto:'.$m->email),
                TextEntry::make('created_at')->label('Received')->dateTime('M j, Y H:i'),
                TextEntry::make('subject')->columnSpanFull(),
                TextEntry::make('message')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('handled_at')->label('Handled')->dateTime('M j, Y H:i')->placeholder('Not yet'),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\IconColumn::make('handled_at')->label('Done')->boolean()->getStateUsing(fn (ContactMessage $m) => $m->handled_at !== null),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('handled_at')->label('Handled')->nullable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('handled')
                    ->label(fn (ContactMessage $m) => $m->handled_at ? 'Reopen' : 'Mark handled')
                    ->icon('heroicon-o-check')
                    ->action(fn (ContactMessage $m) => $m->update(['handled_at' => $m->handled_at ? null : now()])),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListContactMessages::route('/')];
    }
}
