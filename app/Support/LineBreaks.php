<?php

namespace App\Support;

/**
 * Text that went through JSON twice (e.g. an AI tool or a copied JSON string) arrives with the
 * two characters "\" + "n" where a line break was meant. Prose never needs a literal "\n", so
 * those sequences are turned back into real line breaks.
 */
class LineBreaks
{
    public static function fix(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '\\')) {
            return $text;
        }

        return str_replace(['\\r\\n', '\\n', '\\r'], "\n", $text);
    }

    /** Same, for every string in a (nested) array, e.g. FAQs or a card's content. */
    public static function fixAll(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        array_walk_recursive($values, function (mixed &$value) {
            if (is_string($value)) {
                $value = self::fix($value);
            }
        });

        return $values;
    }
}
