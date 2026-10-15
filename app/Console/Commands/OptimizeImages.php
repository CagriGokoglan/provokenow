<?php

namespace App\Console\Commands;

use App\Jobs\OptimizeModelImages;
use App\Models\Deck;
use App\Models\HeroSlide;
use App\Models\InstagramPost;
use App\Models\Product;
use App\Services\Media\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * Retroactively compresses / converts every image already referenced by the catalogue and
 * homepage (new uploads are handled automatically). Safe to re-run: optimised files are skipped.
 */
class OptimizeImages extends Command
{
    protected $signature = 'provoke:optimize-images {--dry-run : List what would be converted without changing anything}';

    protected $description = 'Compress existing uploaded images to WebP (JPEG for social share images)';

    /** @var list<class-string> */
    private const MODELS = [HeroSlide::class, InstagramPost::class, Deck::class, Product::class];

    public function handle(ImageOptimizer $optimizer): int
    {
        $files = 0;
        $bytes = 0;

        foreach (self::MODELS as $model) {
            foreach ($model::query()->cursor() as $record) {
                if ($this->option('dry-run')) {
                    foreach ($this->pending($record, $optimizer) as $path) {
                        $this->line("  would convert {$path} (".Number::fileSize($optimizer->disk()->size($path)).')');
                        $files++;
                        $bytes += $optimizer->disk()->size($path);
                    }

                    continue;
                }

                [$converted, $saved] = (new OptimizeModelImages($model, (string) $record->getKey()))->handle($optimizer);
                $files += $converted;
                $bytes += $saved;
            }
        }

        $this->info($this->option('dry-run')
            ? "{$files} images would be converted (".Number::fileSize($bytes).' today).'
            : "{$files} images converted, ".Number::fileSize(max(0, $bytes)).' saved.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function pending(object $record, ImageOptimizer $optimizer): array
    {
        $paths = [];
        foreach ($record->imageFields() as $field => $profile) {
            [$attribute, $subPath] = array_pad(explode('.', $field, 2), 2, null);
            $value = $subPath ? data_get($record->{$attribute}, $subPath) : $record->{$attribute};
            foreach ((array) $value as $path) {
                if (is_string($path) && $path !== '' && $optimizer->shouldOptimize($path, $profile) && $optimizer->disk()->exists($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
