<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DeckSegment: string implements HasColor, HasLabel
{
    case LightIgnition = 'Light_Ignition';
    case MediumSpice = 'Medium_Spice';
    case AdvancedTaboo = 'Advanced_Taboo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    /** Plain-language audience line, used in meta descriptions and FAQ answers. */
    public function audience(): string
    {
        return match ($this) {
            self::LightIgnition => 'Couples who want playful, low-pressure ways to spark conversation and closeness.',
            self::MediumSpice => 'Couples ready to turn up the heat with bolder, more daring prompts.',
            self::AdvancedTaboo => 'Experienced couples exploring adventurous themes, with consent and safety notes on every card.',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LightIgnition => 'success',
            self::MediumSpice => 'warning',
            self::AdvancedTaboo => 'danger',
        };
    }
}
