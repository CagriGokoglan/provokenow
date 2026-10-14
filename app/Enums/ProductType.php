<?php

namespace App\Enums;

enum ProductType: string
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
}
