<?php

namespace Tests\Unit\Support;

use App\Support\VideoEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for the landing page "video section not showing" bug —
 * a Shorts URL fell through every branch and returned null, silently
 * hiding the section (see hero.blade.php / media.blade.php, which only
 * render an <iframe> when this returns non-null).
 */
class VideoEmbedTest extends TestCase
{
    #[DataProvider('youtubeUrls')]
    public function test_it_extracts_the_video_id_from_every_supported_youtube_url_shape(string $url, string $expectedId): void
    {
        $this->assertSame("https://www.youtube.com/embed/{$expectedId}", VideoEmbed::url($url));
    }

    public static function youtubeUrls(): array
    {
        return [
            'watch?v=' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch?v= with trailing params' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', 'dQw4w9WgXcQ'],
            'youtu.be short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts without www' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    public function test_an_already_embed_url_passes_through_unchanged(): void
    {
        $url = 'https://www.youtube.com/embed/dQw4w9WgXcQ';

        $this->assertSame($url, VideoEmbed::url($url));
    }

    public function test_a_facebook_video_link_is_wrapped_in_the_plugin_embed_url(): void
    {
        $embed = VideoEmbed::url('https://www.facebook.com/watch/?v=123456');

        $this->assertStringStartsWith('https://www.facebook.com/plugins/video.php?href=', $embed);
    }

    public function test_null_and_empty_string_never_produce_an_embed_url(): void
    {
        $this->assertNull(VideoEmbed::url(null));
        $this->assertNull(VideoEmbed::url(''));
    }

    public function test_an_unrecognized_url_returns_null_rather_than_a_broken_embed(): void
    {
        $this->assertNull(VideoEmbed::url('https://example.com/some-video'));
    }
}
