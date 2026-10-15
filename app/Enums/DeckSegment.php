<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** The four intensity levels decks are browsed by (/decks?level=…). */
enum DeckSegment: string implements HasColor, HasLabel
{
    case LightIgnition = 'Light_Ignition';
    case MediumSpice = 'Medium_Spice';
    case Advanced = 'Advanced';
    case Taboo = 'Taboo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === strtolower($slug)) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    /** URL form used by the web app, e.g. "medium-spice". */
    public function slug(): string
    {
        return strtolower(str_replace('_', '-', $this->value));
    }

    /** 1–4, the order levels are presented in. */
    public function rank(): int
    {
        return match ($this) {
            self::LightIgnition => 1,
            self::MediumSpice => 2,
            self::Advanced => 3,
            self::Taboo => 4,
        };
    }

    /** Plain-language audience line, used in meta descriptions and FAQ answers. */
    public function audience(): string
    {
        return match ($this) {
            self::LightIgnition => 'Couples who want playful, low-pressure ways to spark conversation and closeness.',
            self::MediumSpice => 'Couples ready to turn up the heat with bolder, more daring prompts.',
            self::Advanced => 'Experienced couples exploring adventurous themes, with consent and safety notes on every card.',
            self::Taboo => 'Couples who know their limits well and want to explore the edges together, with consent written into every card.',
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
            self::Advanced => 'danger',
            self::Taboo => 'gray',
        };
    }
}
