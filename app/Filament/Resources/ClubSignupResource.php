<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClubSignupResource\Pages;
use App\Models\ClubSignup;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Provoke Club waitlist (read-only; export as CSV). */
class ClubSignupResource extends Resource
{
    protected static ?string $model = ClubSignup::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Provoke Club';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('user.name')->label('Account')->placeholder('Guest'),
                Tables\Columns\TextColumn::make('source')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->actions([Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListClubSignups::route('/')];
    }
}
