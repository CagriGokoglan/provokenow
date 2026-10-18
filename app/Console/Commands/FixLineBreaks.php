<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\Deck;
use App\Models\LegalPage;
use App\Models\Post;
use App\Support\FrontendCache;
use App\Support\LineBreaks;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/** One-off repair: literal "\n" sequences in saved content become real line breaks. */
class FixLineBreaks extends Command
{
    protected $signature = 'content:fix-line-breaks {--dry-run : List what would change without saving}';

    protected $description = 'Turn literal "\n" text (from double-encoded JSON) into real line breaks in decks, cards, posts and legal pages';

    /** model => [text columns, array columns] */
    private const TARGETS = [
        Deck::class => [Deck::TEXT_FIELDS, ['faqs']],
        Card::class => [[], ['content_payload']],
        Post::class => [['content', 'excerpt', 'tldr', 'seo_description', 'author_bio'], ['faqs']],
        LegalPage::class => [['content', 'seo_description'], []],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        foreach (self::TARGETS as $model => [$text, $arrays]) {
            $model::query()->when($model === Card::class, fn ($q) => $q->with('deck:id,deck_code'))->chunkById(200, function ($records) use ($text, $arrays, $dry, &$changed) {
                foreach ($records as $record) {
                    $fields = $this->fix($record, $text, $arrays);
                    if ($fields === []) {
                        continue;
                    }
                    $changed++;
                    $this->line(sprintf('%s %s: %s', class_basename($record), $this->label($record), implode(', ', $fields)));
                    if (! $dry) {
                        $record->saveQuietly();
                    }
                }
            });
        }

        if ($changed > 0 && ! $dry) {
            FrontendCache::flush();
        }

        $this->info($changed === 0 ? 'Nothing to fix.' : ($dry ? "{$changed} record(s) would be fixed (dry run, nothing saved)." : "{$changed} record(s) fixed."));

        return self::SUCCESS;
    }

    /** @return list<string> the fields that changed */
    private function fix(Model $record, array $text, array $arrays): array
    {
        foreach ($text as $field) {
            $record->{$field} = LineBreaks::fix($record->{$field});
        }
        foreach ($arrays as $field) {
            $record->{$field} = LineBreaks::fixAll($record->{$field});
        }

        return array_keys($record->getDirty());
    }

    private function label(Model $record): string
    {
        return match (true) {
            $record instanceof Deck => "{$record->deck_code} ({$record->title})",
            $record instanceof Card => ($record->deck?->deck_code ?? '?')." card {$record->card_number}",
            default => (string) ($record->title ?? $record->getKey()),
        };
    }
}
