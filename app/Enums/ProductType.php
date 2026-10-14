<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductType: string implements HasColor, HasLabel
{
    case DigitalDeck = 'digital_deck';
    case PodPhysicalDeck = 'pod_physical_deck';
    case PhysicalAccessory = 'physical_accessory';
    case Bundle = 'bundle';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether this type ships by default (a bundle may override via metadata.requires_shipping). */
    public function shipsByDefault(): bool
    {
        return $this !== self::DigitalDeck;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DigitalDeck => 'Digital Deck',
            self::PodPhysicalDeck => 'POD Physical Deck',
            self::PhysicalAccessory => 'Physical Accessory',
            self::Bundle => 'Bundle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DigitalDeck => 'info',
            self::PodPhysicalDeck => 'primary',
            self::PhysicalAccessory => 'warning',
            self::Bundle => 'success',
        };
    }
}
