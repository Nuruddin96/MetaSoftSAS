<?php

namespace App\Support;

/**
 * Converts a plain video URL a tenant pastes into the landing page builder
 * (YouTube "watch" link, a Facebook video link, or already an /embed/ url)
 * into something safe to drop into an <iframe src="">. Used by the landing
 * page's hero, media, and video-reviews sections — three call sites was the
 * point this stopped being fine to just inline three times.
 */
class VideoEmbed
{
    /** Null when the url doesn't look embeddable — the caller should show a plain link instead of an iframe. */
    public static function url(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        // watch?v=, youtu.be/, and shorts/ — a Shorts link previously fell
        // through to null (no branch matched it at all), silently hiding
        // the video section for exactly that URL shape.
        if (preg_match('~youtu(?:be\.com/(?:watch\?v=|shorts/)|\.be/)([\w-]+)~', $url, $m)) {
            return 'https://www.youtube.com/embed/'.$m[1];
        }

        if (str_contains($url, 'youtube.com/embed/')) {
            return $url;
        }

        if (str_contains($url, 'facebook.com/') || str_contains($url, 'fb.watch/')) {
            return 'https://www.facebook.com/plugins/video.php?href='.urlencode($url);
        }

        return null;
    }
}
