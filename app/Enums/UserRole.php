<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case Customer = 'customer';
    case Admin = 'admin';
    case SuperAdmin = 'superadmin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Admin => 'Admin',
            self::SuperAdmin => 'Superadmin',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'gray',
            self::Admin => 'info',
            self::SuperAdmin => 'primary',
        };
    }
}
