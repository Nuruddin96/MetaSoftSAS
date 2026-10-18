<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link to a Facebook Page (or a page-style profile): facebook.com/{name},
 * facebook.com/profile.php?id={number}, facebook.com/p/{name-id} or
 * facebook.com/pages/{name}/{id}. Rejects other sites, the bare domain and
 * Facebook's own non-page paths (login, share, groups, watch…).
 *
 * This checks the link's FORMAT only. It does not prove the page exists or
 * that the person registering owns it — the admin review does not claim
 * that either.
 */
class FacebookPageUrl implements ValidationRule
{
    /** Browser-side pre-check for the form's pattern attribute; the server check (passes()) is the authority. */
    public const HTML_PATTERN = '(https?://)?((www|m|web|mbasic)\.)?(facebook|fb)\.com/.+';

    public const MESSAGE = 'Enter your business’s Facebook Page link, for example https://www.facebook.com/yourbrand.';

    private const HOSTS = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com', 'mbasic.facebook.com', 'fb.com', 'www.fb.com'];

    private const NOT_PAGES = ['login', 'login.php', 'logout.php', 'sharer', 'sharer.php', 'share', 'share.php', 'dialog', 'plugins', 'home.php',
        'watch', 'groups', 'events', 'marketplace', 'gaming', 'help', 'settings', 'privacy', 'policies', 'policy.php', 'terms', 'search', 'hashtag',
        'l.php', 'story.php', 'photo.php', 'photo', 'photos', 'reel', 'reels', 'stories', 'messages', 'notifications', 'friends', 'bookmarks',
        'ads', 'business', 'recover', 'checkpoint', 'r.php', 'reg', 'signup'];

    /** Trim, and add https:// to a pasted "facebook.com/…" link so the browser-less form of a URL is accepted. */
    public static function normalize(?string $raw): ?string
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return null;
        }
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) && preg_match('~^(?:(?:www|m|web|mbasic)\.)?(?:facebook|fb)\.com(?:/|$)~i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    public static function passes(mixed $value): bool
    {
        if (! is_string($value) || mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/')), 'strlen'));
        $first = strtolower($segments[0] ?? '');
        $name = '~^[\pL\pN._-]{2,100}$~u';

        return match (true) {
            $first === '' => false,
            $first === 'profile.php' => (bool) preg_match('~(?:^|&)id=\d{5,}(?:&|$)~', $parts['query'] ?? ''),
            $first === 'p' => isset($segments[1]) && (bool) preg_match($name, rawurldecode($segments[1])),
            $first === 'pages' => count($segments) >= 3,
            in_array($first, self::NOT_PAGES, true) => false,
            default => (bool) preg_match($name, rawurldecode($segments[0])),
        };
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value)) {
            $fail(self::MESSAGE);
        }
    }
}
