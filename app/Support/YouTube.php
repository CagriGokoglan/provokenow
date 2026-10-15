<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Homepage and hero videos are YouTube links; the web app embeds them by video id. */
final class YouTube
{
    private const PATTERN = '~^https?://(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$~i';

    /** The 11-character video id from any common YouTube link, or null. */
    public static function id(?string $url): ?string
    {
        return $url !== null && preg_match(self::PATTERN, trim($url), $m) ? $m[1] : null;
    }

    /** Validation rule: empty, or a link we can extract a video id from. */
    public static function rule(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (filled($value) && YouTube::id((string) $value) === null) {
                    $fail('Paste a YouTube video link, e.g. https://www.youtube.com/watch?v=… or https://youtu.be/…');
                }
            }
        };
    }
}
