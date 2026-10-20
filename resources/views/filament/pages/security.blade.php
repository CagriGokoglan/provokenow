<x-filament-panels::page>
    @if ($recoveryCodes)
        <x-filament::section icon="heroicon-o-key" icon-color="warning">
            <x-slot name="heading">Save your recovery codes</x-slot>
            <x-slot name="description">If you lose your phone, each code signs you in once. They are shown only now: store them in a password manager.</x-slot>
            {{-- Inline grid: the admin's prebuilt stylesheet has no grid-cols utilities. --}}
            <div class="font-mono text-sm" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.5rem">
                @foreach ($recoveryCodes as $recovery)
                    <code class="rounded-lg bg-gray-100 px-3 py-2 text-center dark:bg-white/5">{{ $recovery }}</code>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($this->isEnabled())
        <x-filament::section icon="heroicon-o-shield-check" icon-color="success">
            <x-slot name="heading">Two-factor authentication is on</x-slot>
            <x-slot name="description">Signing in to the admin needs your password and a code from your authenticator app.</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Enabled {{ $this->user()->two_factor_confirmed_at?->diffForHumans() }} · {{ $this->remaining() }} recovery {{ \Illuminate\Support\Str::plural('code', $this->remaining()) }} left.
            </p>
        </x-filament::section>
    @elseif ($qrSvg)
        <x-filament::section icon="heroicon-o-qr-code">
            <x-slot name="heading">Scan with your authenticator app</x-slot>
            <x-slot name="description">Google Authenticator, 1Password, Authy or Microsoft Authenticator. Then enter the 6-digit code it shows.</x-slot>
            <div class="flex flex-col gap-6 md:flex-row md:items-start">
                <div class="w-[220px] shrink-0 rounded-xl bg-white p-3">{{ $this->qrHtml() }}</div>
                <div class="flex-1 space-y-4">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Can't scan? Enter this key:</p>
                        <code class="mt-1 block select-all font-mono text-base tracking-wider">{{ $manualKey }}</code>
                    </div>
                    <form wire:submit="confirmSetup" class="space-y-3">
                        <x-filament::input.wrapper :valid="! $errors->has('code')">
                            <x-filament::input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" maxlength="7" />
                        </x-filament::input.wrapper>
                        @error('code') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                        <div class="flex gap-3">
                            <x-filament::button type="submit">Confirm and turn on</x-filament::button>
                            <x-filament::button type="button" color="gray" wire:click="cancelSetup">Cancel</x-filament::button>
                        </div>
                    </form>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-shield-exclamation" icon-color="warning">
            <x-slot name="heading">Two-factor authentication is off</x-slot>
            <x-slot name="description">Add a second step to the admin sign-in: a code from an authenticator app on your phone. Someone with your password alone can no longer get in.</x-slot>
            <x-filament::button wire:click="startSetup" icon="heroicon-o-qr-code">Enable two-factor authentication</x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
