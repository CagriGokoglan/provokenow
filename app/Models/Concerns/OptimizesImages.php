<?php

namespace App\Models\Concerns;

use App\Jobs\OptimizeModelImages;
use Illuminate\Database\Eloquent\Model;

/**
 * Uploaded images are compressed / converted in the background after the record is saved.
 * Models list their image attributes in imageFields(): ['image' => 'web', 'metadata.images' => 'web'].
 *
 * @mixin Model
 */
trait OptimizesImages
{
    /** @return array<string, string> attribute (dot path into a JSON column allowed) => ImageOptimizer profile */
    abstract public function imageFields(): array;

    public static function bootOptimizesImages(): void
    {
        static::saved(function (Model $model) {
            /** @var Model&self $model */
            $changed = collect(array_keys($model->imageFields()))
                ->contains(fn (string $field) => $model->wasRecentlyCreated || $model->wasChanged(explode('.', $field)[0]));

            if ($changed) {
                OptimizeModelImages::dispatch($model::class, (string) $model->getKey());
            }
        });
    }
}
