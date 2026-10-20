<?php

namespace App\Filament\Resources;

use App\Enums\GiftStatus;
use App\Filament\Resources\GiftResource\Pages;
use App\Models\Gift;
use App\Services\Gifts\GiftService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Gifts bought for someone else: who, when, and whether it was claimed. */
class GiftResource extends Resource
{
    protected static ?string $model = Gift::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Gifts';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['order', 'sender']))
            ->columns([
                Tables\Columns\TextColumn::make('order.order_number')->label('Order')->searchable()
                    ->url(fn (Gift $g) => OrderResource::getUrl('view', ['record' => $g->order_id])),
                Tables\Columns\TextColumn::make('sender.email')->label('From')->description(fn (Gift $g) => $g->sender_alias)->searchable(),
                Tables\Columns\TextColumn::make('recipient_email')->label('To')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('deliver_at')->label('Scheduled')->dateTime('M j, Y')->placeholder('On payment')->sortable(),
                Tables\Columns\TextColumn::make('claimed_at')->label('Claimed')->since()->placeholder('—')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(GiftStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('resend')
                    ->label('Resend email')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription('Sends the recipient a new claim link (the old one stops working) and restarts the '.Gift::CLAIM_DAYS.'-day claim window.')
                    ->visible(fn (Gift $g) => in_array($g->status, [GiftStatus::Sent, GiftStatus::Expired], true) && $g->order?->isPaid())
                    ->action(function (Gift $g, GiftService $gifts) {
                        $gifts->deliver($g);
                        Notification::make()->success()->title('Gift email sent again')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListGifts::route('/')];
    }
}
