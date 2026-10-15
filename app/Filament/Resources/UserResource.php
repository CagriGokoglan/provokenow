<?php

namespace App\Filament\Resources;

use App\Enums\EntitlementStatus;
use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\EntitlementsRelationManager;
use App\Models\User;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Section::make('Account')->columnSpan(2)->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state) => mb_strtolower(trim($state))),
                Select::make('role')
                    ->options(fn () => auth()->user()->isSuperAdmin() ? UserRole::class : [UserRole::Customer->value => 'Customer'])
                    ->default(UserRole::Customer->value)
                    ->required()
                    ->native(false)
                    ->disabled(fn (?User $record) => $record !== null && ! auth()->user()->can('changeRole', $record))
                    ->helperText(fn () => auth()->user()->isSuperAdmin() ? null : 'Only superadmins can grant staff roles.'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->rule(Password::min(10)->letters()->mixedCase()->numbers())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
            ]),
            Group::make()->columnSpan(1)->schema([
                Section::make('Profile')->relationship('profile')->schema([
                    Toggle::make('marketing_opt_in')->label('Marketing opt-in'),
                ]),
                Section::make('Activity')->hiddenOn('create')->schema([
                    Placeholder::make('vault')
                        ->label('Vault passcode')
                        ->content(fn (?User $record) => $record?->profile?->hasVaultPasscode() ? 'Set' : 'Not set'),
                    Placeholder::make('created_at')->label('Joined')->content(fn (?User $record) => $record?->created_at?->toFormattedDateString()),
                    Placeholder::make('orders')->content(fn (?User $record) => (string) $record?->orders()->count()),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'entitlements as decks_owned' => fn (Builder $q) => $q->where('status', EntitlementStatus::Active->value),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('role')->badge()->sortable(),
                Tables\Columns\TextColumn::make('decks_owned')->label('Decks')->alignCenter()->sortable(),
                Tables\Columns\IconColumn::make('email_verified_at')->label('Verified')->boolean()
                    ->state(fn (User $record) => $record->email_verified_at !== null),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->options(UserRole::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [EntitlementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }
}
