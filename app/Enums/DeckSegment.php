<?php

namespace App\Enums;

enum DeckSegment: string
{
    case LightIgnition = 'Light_Ignition';
    case MediumSpice = 'Medium_Spice';
    case AdvancedTaboo = 'Advanced_Taboo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
