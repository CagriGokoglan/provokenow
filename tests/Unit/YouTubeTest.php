<?php

namespace Tests\Unit;

use App\Support\YouTube;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YouTubeTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function links(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch with params' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=30', 'dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'other site' => ['https://vimeo.com/123456', null],
            'lookalike host' => ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', null],
            'channel' => ['https://www.youtube.com/@provokenow', null],
        ];
    }

    #[DataProvider('links')]
    public function test_extracts_the_video_id(string $url, ?string $id): void
    {
        $this->assertSame($id, YouTube::id($url));
    }
}
