<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** pending: paid or awaiting payment, not yet sent · sent: recipient emailed · claimed · expired · cancelled (refund / failed payment). */
enum GiftStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Claimed = 'claimed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sent => 'warning',
            self::Claimed => 'success',
            self::Expired, self::Cancelled => 'danger',
        };
    }
}
