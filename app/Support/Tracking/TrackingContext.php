<?php

namespace App\Support\Tracking;

use Illuminate\Http\Request;

/**
 * Browser-side signals needed for server-side conversion events.
 *
 * The frontend's consent banner sends `X-Tracking-Consent: granted` once the visitor has
 * accepted marketing cookies. Without it nothing is sent to Meta. Meta browser IDs are read
 * from the _fbp/_fbc cookies or the X-Fbp/X-Fbc headers (for cross-origin API calls).
 */
final readonly class TrackingContext
{
    public const CONSENT_HEADER = 'X-Tracking-Consent';

    public function __construct(
        public bool $consent,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $fbp = null,
        public ?string $fbc = null,
        public ?string $sourceUrl = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $consent = strtolower((string) $request->header(self::CONSENT_HEADER)) === 'granted';

        if (! $consent) {
            return new self(false);
        }

        return new self(
            consent: true,
            ip: $request->ip(),
            userAgent: self::clip($request->userAgent(), 512),
            fbp: self::clip($request->header('X-Fbp') ?? $request->cookie('_fbp'), 255),
            fbc: self::clip($request->header('X-Fbc') ?? $request->cookie('_fbc'), 255),
            sourceUrl: self::clip($request->header('X-Page-Url') ?? $request->header('Referer'), 1024),
        );
    }

    /** Rehydrate from order metadata (stored at checkout). */
    public static function fromArray(?array $data): self
    {
        if (! ($data['consent'] ?? false)) {
            return new self(false);
        }

        return new self(true, $data['ip'] ?? null, $data['user_agent'] ?? null, $data['fbp'] ?? null, $data['fbc'] ?? null, $data['source_url'] ?? null);
    }

    public function toArray(): array
    {
        return [
            'consent' => $this->consent,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
            'source_url' => $this->sourceUrl,
        ];
    }

    private static function clip(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
