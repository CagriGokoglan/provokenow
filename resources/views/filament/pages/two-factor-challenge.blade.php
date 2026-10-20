<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="verify">
        {{ $this->form }}

        <x-filament::button type="submit" class="w-full">
            Verify
        </x-filament::button>
    </x-filament-panels::form>

    <div class="mt-2 flex flex-col items-center gap-3 text-sm">
        {{-- Email fallback, with a 60-second cooldown before it can be sent again (restarted on each send). --}}
        <div wire:key="email-code-{{ $emailSentAt }}" x-data="{ left: @js($emailCooldown) }" x-init="setInterval(() => { if (left > 0) left-- }, 1000)">
            <span x-show="left > 0" x-cloak class="text-gray-500 dark:text-gray-400">Resend email code in <span x-text="left"></span>s</span>
            <span x-show="left <= 0">
                <x-filament::link tag="button" wire:click="sendEmailCode" wire:loading.attr="disabled">
                    {{ $emailSent ? 'Resend email code' : 'Lost access to device? Send code via email' }}
                </x-filament::link>
            </span>
        </div>
        <x-filament::link tag="button" color="gray" wire:click="toggleRecovery">
            {{ $useRecovery ? 'Use a 6-digit code instead' : 'Use a recovery code' }}
        </x-filament::link>
        <x-filament::link tag="button" color="gray" wire:click="signOut">
            Sign out
        </x-filament::link>
    </div>
</x-filament-panels::page.simple>
