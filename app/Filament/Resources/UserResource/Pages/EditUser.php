<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Access\VaultService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resetVault')
                ->label('Reset vault passcode')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn (User $record) => (bool) $record->profile?->hasVaultPasscode())
                ->requiresConfirmation()
                ->modalDescription('Removes the customer\'s vault passcode (e.g. if they forgot it). They can set a new one in the app.')
                ->action(function (User $record) {
                    $record->profile->forceFill(['digital_vault_passcode_hash' => null])->save();
                    app(VaultService::class)->lock($record);
                    Notification::make()->success()->title('Vault passcode removed')->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /** `role` is not mass-assignable and may only change when the policy allows it. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $role = UserRole::tryFrom((string) ($data['role'] ?? ''));
        unset($data['role']);

        $record->fill($data);
        if ($role && $role !== $record->role && auth()->user()->can('changeRole', $record)) {
            $record->role = $role;
        }
        $record->save();

        return $record;
    }
}
