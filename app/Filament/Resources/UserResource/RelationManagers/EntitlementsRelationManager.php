<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\EntitlementStatus;
use App\Models\Deck;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\Access\EntitlementService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Deck access for one customer. Rows are never deleted, only revoked, to keep an audit trail. */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    protected static ?string $title = 'Deck access';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('granted_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['deck', 'order']))
            ->columns([
                Tables\Columns\TextColumn::make('deck.deck_code')->label('Deck')->weight('medium')->searchable(),
                Tables\Columns\TextColumn::make('deck.title')->label('Title')->limit(40),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->state(fn (UserEntitlement $record) => $record->status === EntitlementStatus::Active && ! $record->isValid()
                        ? 'expired' : $record->status)
                    ->formatStateUsing(fn ($state) => $state === 'expired' ? 'Expired' : $state->getLabel())
                    ->color(fn ($state) => $state === 'expired' ? 'gray' : $state->getColor()),
                Tables\Columns\TextColumn::make('granted_at')->dateTime('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime('M j, Y')->placeholder('Lifetime'),
                Tables\Columns\TextColumn::make('order.order_number')->label('Source')->placeholder('Manual grant'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(EntitlementStatus::class),
            ])
            ->headerActions([
                Action::make('grant')
                    ->label('Grant Deck Access')
                    ->icon('heroicon-o-key')
                    ->form([
                        Select::make('deck_ids')
                            ->label('Decks')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->options(fn () => Deck::orderBy('deck_code')->get()
                                ->mapWithKeys(fn (Deck $d) => [$d->id => "{$d->deck_code} — {$d->title}"])),
                        DateTimePicker::make('expires_at')
                            ->label('Expires')
                            ->helperText('Leave empty for lifetime access.')
                            ->minDate(now())
                            ->native(false),
                    ])
                    ->action(function (array $data, EntitlementService $entitlements) {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        $expires = filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null;

                        $granted = DB::transaction(fn () => $entitlements->grant($user, $data['deck_ids'], null, $expires));

                        Notification::make()->success()->title("Access granted to {$granted->count()} deck(s)")->send();
                    }),
            ])
            ->actions([
                Action::make('revoke')
                    ->label('Revoke Access')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (UserEntitlement $record) => $record->status === EntitlementStatus::Active)
                    ->requiresConfirmation()
                    ->modalDescription('The customer immediately loses access to this deck. The record is kept for history.')
                    ->action(function (UserEntitlement $record) {
                        $record->revoke();
                        Notification::make()->success()->title('Access revoked')->send();
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
