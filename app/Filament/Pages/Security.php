<?php

namespace App\Filament\Pages;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/** Two-factor authentication for the signed-in staff member: set up, recovery codes, turn off. */
class Security extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Security (2FA)';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'security';

    protected static string $view = 'filament.pages.security';

    protected static ?string $title = 'Two-factor authentication';

    /** Setup in progress: the QR code and the key to type in by hand. */
    public ?string $qrSvg = null;

    public ?string $manualKey = null;

    public string $code = '';

    /** Shown once, right after they are created. */
    public array $recoveryCodes = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false; // In the user menu instead.
    }

    public function user(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }

    public function isEnabled(): bool
    {
        return $this->user()->hasTwoFactorEnabled();
    }

    public function remaining(): int
    {
        return app(TwoFactor::class)->remainingRecoveryCodes($this->user());
    }

    public function mount(): void
    {
        if (! $this->isEnabled() && config('provoke.admin.require_two_factor')) {
            Notification::make()->warning()->title('Set up two-factor authentication to continue.')->send();
        }
    }

    public function startSetup(TwoFactor $twoFactor): void
    {
        if ($this->isEnabled()) {
            return;
        }

        $setup = $twoFactor->begin($this->user());
        $this->qrSvg = $setup['qr_svg'];
        $this->manualKey = trim(chunk_split($setup['secret'], 4, ' '));
        // Kept server-side until the setup is confirmed, then shown once.
        session()->put('two_factor_pending_codes', encrypt($setup['recovery_codes']));
        $this->code = '';
    }

    public function confirmSetup(TwoFactor $twoFactor): void
    {
        $this->validate(['code' => ['required', 'string', 'max:10']]);

        try {
            $twoFactor->confirm($this->user(), $this->code);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['code' => $e->errors()['code'] ?? ['That code is not valid.']]);
        }

        // They just proved the code: this session counts as verified.
        RequireTwoFactor::markPassed(request(), $this->user());
        $this->recoveryCodes = decrypt(session()->pull('two_factor_pending_codes', encrypt([])));
        $this->qrSvg = $this->manualKey = null;
        $this->code = '';

        Notification::make()->success()->title('Two-factor authentication is on')->send();
    }

    public function cancelSetup(TwoFactor $twoFactor): void
    {
        if (! $this->isEnabled()) {
            $twoFactor->disable($this->user());
        }
        session()->forget('two_factor_pending_codes');
        $this->qrSvg = $this->manualKey = null;
    }

    public function qrHtml(): HtmlString
    {
        // Generated server-side from our own data (no user input): safe to inline.
        return new HtmlString((string) $this->qrSvg);
    }

    protected function getHeaderActions(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        return [
            Action::make('regenerate')
                ->label('New recovery codes')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->modalDescription('Your current recovery codes stop working. Enter a code from your authenticator app to continue.')
                ->form([TextInput::make('code')->label('Authenticator code')->required()->autocomplete('one-time-code')])
                ->action(function (array $data, TwoFactor $twoFactor) {
                    $twoFactor->check($this->user(), $data['code']);
                    $this->recoveryCodes = $twoFactor->regenerateRecoveryCodes($this->user());
                }),
            Action::make('disable')
                ->label('Turn off 2FA')
                ->icon('heroicon-o-lock-open')
                ->color('danger')
                ->modalHeading('Turn off two-factor authentication?')
                ->modalDescription(config('provoke.admin.require_two_factor')
                    ? 'Two-factor authentication is required for staff: you will be asked to set it up again.'
                    : 'Your account will be protected by your password only.')
                ->form([
                    TextInput::make('password')->label('Current password')->password()->required()->autocomplete('current-password'),
                    TextInput::make('code')->label('Authenticator or recovery code')->required()->autocomplete('one-time-code'),
                ])
                ->action(function (array $data, TwoFactor $twoFactor) {
                    if (! Hash::check($data['password'], $this->user()->password)) {
                        throw ValidationException::withMessages(['mountedActionsData.0.password' => 'The password is incorrect.']);
                    }
                    $twoFactor->check($this->user(), $data['code']);
                    $twoFactor->disable($this->user());
                    $this->recoveryCodes = [];
                    Notification::make()->success()->title('Two-factor authentication is off')->send();
                }),
        ];
    }
}
