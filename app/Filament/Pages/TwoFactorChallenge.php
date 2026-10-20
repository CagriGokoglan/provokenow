<?php

namespace App\Filament\Pages;

use App\Http\Middleware\RequireTwoFactor;
use App\Services\Auth\TwoFactor;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\SimplePage;

/** Step 2 of the admin sign-in: the 6-digit code from the authenticator app (or a recovery code). */
class TwoFactorChallenge extends SimplePage implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.two-factor-challenge';

    protected static ?string $slug = 'two-factor-challenge';

    public ?array $data = [];

    public bool $useRecovery = false;

    public function mount(): void
    {
        $user = Filament::auth()->user();
        if (! $user) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }
        if (! $user->hasTwoFactorEnabled() || RequireTwoFactor::passed(request(), $user)) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Two-factor authentication';
    }

    public function getSubheading(): string
    {
        return $this->useRecovery
            ? 'Enter one of your recovery codes. Each code works once.'
            : 'Enter the verification code from your authenticator app.';
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            TextInput::make('code')
                ->label($this->useRecovery ? 'Recovery code' : 'Verification code')
                ->placeholder($this->useRecovery ? 'XXXXX-XXXXX' : '123456')
                ->required()
                ->autocomplete('one-time-code')
                ->extraInputAttributes($this->useRecovery ? [] : ['inputmode' => 'numeric', 'maxlength' => 7])
                ->autofocus(),
        ]);
    }

    public function toggleRecovery(): void
    {
        $this->useRecovery = ! $this->useRecovery;
        $this->form->fill();
    }

    public function verify(TwoFactor $twoFactor): void
    {
        $user = Filament::auth()->user();
        if (! $user) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $twoFactor->check($user, (string) ($this->form->getState()['code'] ?? ''));

        // A fresh session id once fully signed in.
        session()->regenerate();
        RequireTwoFactor::markPassed(request(), $user);
        $this->redirectIntended(Filament::getUrl());
    }

    public function signOut(): void
    {
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect(Filament::getLoginUrl());
    }
}
