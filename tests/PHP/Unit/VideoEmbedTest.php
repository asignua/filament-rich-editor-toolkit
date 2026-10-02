<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Unit;

use Asignua\RichEditorToolkit\Support\VideoEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VideoEmbedTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, ?int}>
     */
    public static function valid(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', null],
            'watch with other params first' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', null],
            'short' => ['https://youtu.be/dQw4w9WgXcQ?t=90', 'youtube', 'dQw4w9WgXcQ', 90],
            'shorts' => ['https://www.youtube.com/shorts/abcDEF12345', 'youtube', 'abcDEF12345', null],
            'embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', null],
            'no scheme' => ['youtu.be/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', null],
            'h/m/s timecode' => ['https://youtu.be/dQw4w9WgXcQ?t=1h2m3s', 'youtube', 'dQw4w9WgXcQ', 3723],
            'vimeo' => ['https://vimeo.com/123456789', 'vimeo', '123456789', null],
            'vimeo player' => ['https://player.vimeo.com/video/123456789', 'vimeo', '123456789', null],
        ];
    }

    #[DataProvider('valid')]
    public function test_it_recognises_a_link(string $url, string $provider, string $id, ?int $start): void
    {
        $this->assertSame(['provider' => $provider, 'id' => $id, 'start' => $start], VideoEmbed::parse($url));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalid(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'prefixed by a foreign host' => ['https://evil.example/https://youtube.com/watch?v=dQw4w9WgXcQ'],
            'id is longer than allowed (not truncated)' => ['https://youtu.be/dQw4w9WgXcQEXTRAEXTRAEXTRA'],
            'lookalike host' => ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ'],
            'vimeo non numeric' => ['https://vimeo.com/channels/staffpicks'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_it_rejects_everything_else(?string $url): void
    {
        $this->assertNull(VideoEmbed::parse($url));
    }

    public function test_embed_urls_are_rebuilt_without_autoplay(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/abcdef?rel=0', VideoEmbed::embedUrl('youtube', 'abcdef'));
        $this->assertSame('https://www.youtube-nocookie.com/embed/abcdef?rel=0&start=5', VideoEmbed::embedUrl('youtube', 'abcdef', 5));
        $this->assertSame('https://www.youtube-nocookie.com/embed/abcdef?autoplay=1&rel=0', VideoEmbed::embedUrl('youtube', 'abcdef', null, true));
        $this->assertSame('https://player.vimeo.com/video/123456', VideoEmbed::embedUrl('vimeo', '123456'));
        $this->assertSame('https://player.vimeo.com/video/123456?autoplay=1#t=7s', VideoEmbed::embedUrl('vimeo', '123456', 7, true));
    }

    public function test_watch_thumbnail_and_oembed_urls(): void
    {
        $this->assertSame('https://www.youtube.com/watch?v=abcdef&t=9', VideoEmbed::watchUrl('youtube', 'abcdef', 9));
        $this->assertSame('https://vimeo.com/123456#t=9s', VideoEmbed::watchUrl('vimeo', '123456', 9));
        $this->assertCount(2, VideoEmbed::thumbnailUrls('youtube', 'abcdef'));
        $this->assertSame([], VideoEmbed::thumbnailUrls('vimeo', '123456'));
        $this->assertNull(VideoEmbed::oembedUrl('youtube', 'abcdef'));
        $this->assertStringContainsString('vimeo.com/api/oembed.json', (string) VideoEmbed::oembedUrl('vimeo', '123456'));
    }
}
