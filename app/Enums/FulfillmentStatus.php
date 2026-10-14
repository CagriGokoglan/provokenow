<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FulfillmentStatus: string implements HasColor, HasLabel
{
    case Unfulfilled = 'unfulfilled';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Unfulfilled',
            self::Processing => 'Processing',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unfulfilled => 'gray',
            self::Processing => 'warning',
            self::Shipped => 'info',
            self::Delivered => 'success',
        };
    }
}
