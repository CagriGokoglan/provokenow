<?php

namespace App\Support;

/** Courier names for the admin and their public tracking links (config provoke.couriers). */
final class Couriers
{
    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(config('provoke.couriers', []));
    }

    /** The courier's tracking page for this number, or null for unknown / manual couriers. */
    public static function trackingUrl(?string $courier, ?string $number): ?string
    {
        $template = collect(config('provoke.couriers', []))
            ->first(fn ($url, $name) => strcasecmp($name, (string) $courier) === 0);

        return $template && filled($number) ? str_replace('{number}', rawurlencode(trim($number)), $template) : null;
    }
}
