<?php

namespace App\Jobs;

use App\Services\Media\ImageOptimizer;
use App\Support\FrontendCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Compresses / converts every image of one record, then points the record at the new files. */
class OptimizeModelImages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $uniqueFor = 600;

    /** @param class-string<Model> $model */
    public function __construct(public string $model, public string $key) {}

    public function uniqueId(): string
    {
        return $this->model.':'.$this->key;
    }

    /** @return array{0: int, 1: int} [files converted, bytes saved] */
    public function handle(ImageOptimizer $optimizer): array
    {
        // GD holds the decoded bitmap in memory (a 24 MP photo is ~100 MB).
        ini_set('memory_limit', '512M');

        $record = $this->model::find($this->key);
        if (! $record) {
            return [0, 0];
        }

        $replaced = [];
        foreach ($record->imageFields() as $field => $profile) {
            [$attribute, $subPath] = array_pad(explode('.', $field, 2), 2, null);
            $value = $subPath ? data_get($record->{$attribute}, $subPath) : $record->{$attribute};

            $swap = function (?string $path) use ($optimizer, $profile, &$replaced) {
                $new = is_string($path) && $path !== '' ? $optimizer->optimize($path, $profile) : null;
                if ($new === null) {
                    return $path;
                }
                $replaced[$path] = $new;

                return $new;
            };

            $updated = is_array($value) ? array_map($swap, $value) : $swap($value);
            if ($updated === $value) {
                continue;
            }

            if ($subPath) {
                $json = $record->{$attribute};
                data_set($json, $subPath, $updated);
                $record->{$attribute} = $json;
            } else {
                $record->{$attribute} = $updated;
            }
        }

        if ($replaced === []) {
            return [0, 0];
        }

        // Quietly: no second optimisation pass, no admin-side events for a background change.
        $record->saveQuietly();

        $disk = $optimizer->disk();
        $saved = 0;
        foreach ($replaced as $old => $new) {
            if ($disk->exists($old)) {
                $saved += $disk->size($old) - $disk->size($new);
                $disk->delete($old);
            }
        }

        FrontendCache::flush();

        return [count($replaced), $saved];
    }
}
