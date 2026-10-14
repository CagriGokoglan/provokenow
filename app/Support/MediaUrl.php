<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** Image fields hold either an absolute URL or a path on the public disk (admin uploads). */
final class MediaUrl
{
    public static function resolve(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
