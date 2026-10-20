<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="verify">
        {{ $this->form }}

        <x-filament::button type="submit" class="w-full">
            Verify
        </x-filament::button>
    </x-filament-panels::form>

    <div class="mt-2 flex flex-col items-center gap-3 text-sm">
        <x-filament::link tag="button" wire:click="toggleRecovery">
            {{ $useRecovery ? 'Use the authenticator app instead' : 'Lost your device? Use a recovery code' }}
        </x-filament::link>
        <x-filament::link tag="button" color="gray" wire:click="signOut">
            Sign out
        </x-filament::link>
    </div>
</x-filament-panels::page.simple>
