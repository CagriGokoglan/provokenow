<?php

namespace App\Support;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Strips scripts, event handlers and external references from uploaded SVGs. An SVG opened
 * directly from the API origin would otherwise run script next to the admin session.
 */
final class SvgSanitizer
{
    public static function sanitizeStored(?string $path, string $field, string $disk = 'public'): void
    {
        if ($path === null || preg_match('#^https?://#i', $path) || ! str_ends_with(strtolower($path), '.svg')) {
            return;
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return;
        }

        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize((string) $storage->get($path));

        if (! $clean) {
            $storage->delete($path);

            throw ValidationException::withMessages([$field => 'This SVG could not be processed safely.']);
        }

        $storage->put($path, $clean);
    }
}
