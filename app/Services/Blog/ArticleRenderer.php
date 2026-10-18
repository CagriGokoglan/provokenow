<?php

namespace App\Services\Blog;

use Illuminate\Support\Str;

/**
 * Turns a post's Markdown into safe HTML split at each H2, with anchored headings for the
 * jump-link table of contents. The web app places product CTA cards between sections.
 */
class ArticleRenderer
{
    /** @return array{sections: list<string>, toc: list<array{id: string, title: string}>, reading_minutes: int} */
    public function render(string $markdown): array
    {
        // Raw HTML in the Markdown is stripped and unsafe links dropped: only Markdown formatting renders.
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $toc = [];
        $used = [];
        $html = preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function (array $m) use (&$toc, &$used) {
            $title = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5));
            $id = Str::slug($title) ?: 'section';
            $base = $id;
            for ($i = 2; isset($used[$id]); $i++) {
                $id = "{$base}-{$i}";
            }
            $used[$id] = true;
            if ($m[1] === '2') {
                $toc[] = ['id' => $id, 'title' => $title];
            }

            return "<h{$m[1]} id=\"{$id}\">{$m[2]}</h{$m[1]}>";
        }, $html);

        // Split before every H2 (the intro before the first H2 is its own section).
        $sections = array_values(array_filter(
            preg_split('#(?=<h2 id=)#', $html),
            fn (string $part) => trim($part) !== '',
        ));

        $words = str_word_count(strip_tags($html));

        return ['sections' => $sections, 'toc' => $toc, 'reading_minutes' => max(1, (int) ceil($words / 220))];
    }
}
