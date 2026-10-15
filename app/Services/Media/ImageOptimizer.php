<?php

namespace App\Services\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Re-encodes admin uploads on the public disk: site imagery becomes WebP, capped in size;
 * social share images stay JPEG (what every network accepts) at 1200 px wide. EXIF and other
 * metadata are dropped along the way. Responsive sizes and AVIF are produced per request by
 * the web app's image optimizer (next/image), so no size variants are stored here.
 */
class ImageOptimizer
{
    public const PROFILE_WEB = 'web';

    public const PROFILE_OG = 'og';

    /** Formats we re-encode. SVG (sanitised vector art) and GIF (may be animated) are left alone. */
    private const RASTER = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'bmp'];

    public function __construct(private readonly ImageManager $images = new ImageManager(new Driver)) {}

    /**
     * Writes an optimised copy next to the original and returns its path, or null when the file
     * is skipped (remote URL, vector, already optimised, missing, or no smaller). The caller
     * swaps the stored path and then deletes the original.
     */
    public function optimize(string $path, string $profile = self::PROFILE_WEB): ?string
    {
        if (! $this->shouldOptimize($path, $profile)) {
            return null;
        }

        $disk = $this->disk();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $target = $this->targetPath($path, $profile);

        // An admin form still open from before the conversion can save the old path back; the
        // original is gone but its converted twin is there, so point at that.
        if (! $disk->exists($path)) {
            return $disk->exists($target) ? $target : null;
        }

        try {
            $original = $disk->get($path);
            $image = $this->images->read($original);

            if ($profile === self::PROFILE_OG) {
                $encoded = (string) $image->scaleDown(width: (int) config('provoke.images.og_width'))->toJpeg((int) config('provoke.images.og_quality'));
            } else {
                $max = (int) config('provoke.images.max_dimension');
                $encoded = (string) $image->scaleDown(width: $max, height: $max)->toWebp((int) config('provoke.images.quality'));
            }
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        // Already-lean WebP/JPEG uploads can come out larger; keep those as they are.
        if ($extension === ($profile === self::PROFILE_OG ? 'jpg' : 'webp') && strlen($encoded) >= strlen($original)) {
            return null;
        }

        $disk->put($target, $encoded, 'public');

        return $target;
    }

    /** A local raster upload that has not been through the optimiser yet. */
    public function shouldOptimize(string $path, string $profile): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return ! preg_match('#^https?://#i', $path)
            && in_array($extension, self::RASTER, true)
            && ! $this->isOptimized($path, $profile);
    }

    /** Our own outputs carry a recognisable name, so re-runs never re-compress them. */
    public function isOptimized(string $path, string $profile): bool
    {
        return $profile === self::PROFILE_OG ? str_ends_with($path, '-og.jpg') : str_ends_with($path, '.o.webp');
    }

    public function disk(): Filesystem
    {
        return Storage::disk('public');
    }

    /** Deterministic: photo.jpg → photo.o.webp (site) or photo-og.jpg (social share). */
    private function targetPath(string $path, string $profile): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $base = ($directory === '.' ? '' : $directory.'/').pathinfo($path, PATHINFO_FILENAME);

        return $base.($profile === self::PROFILE_OG ? '-og.jpg' : '.o.webp');
    }
}
