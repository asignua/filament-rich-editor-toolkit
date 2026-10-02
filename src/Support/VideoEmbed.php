<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

/**
 * Parses YouTube and Vimeo links and builds canonical embed/watch URLs.
 *
 * The single source of truth for what counts as a valid video link: the form validation and
 * the renderer both use it. The string the user typed never reaches the markup — only a URL
 * rebuilt from the recognised provider and id.
 */
final class VideoEmbed
{
    /**
     * @return array{provider: string, id: string, start: ?int}|null
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $start = self::parseStart($url);

        // The ^ anchor is mandatory: without it "https://evil.example/https://youtube.com/..."
        // would pass. So is the lookahead (?![A-Za-z0-9_-]) after every capture: without it
        // "youtu.be/dQw4w9WgXcQEXTRA" would silently truncate the id to its first 20 characters
        // instead of being rejected — ?, &, /, # and the end of the string stay acceptable
        // boundaries.
        $youtube = '~^(?:https?://)?(?:www\.|m\.)?youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/|v/)([A-Za-z0-9_-]{6,20})(?![A-Za-z0-9_-])~i';
        $youtubeShort = '~^(?:https?://)?(?:www\.)?youtu\.be/([A-Za-z0-9_-]{6,20})(?![A-Za-z0-9_-])~i';
        $vimeo = '~^(?:https?://)?(?:www\.)?vimeo\.com/(?:video/)?(\d{6,12})(?![A-Za-z0-9_-])~i';
        $vimeoPlayer = '~^(?:https?://)?player\.vimeo\.com/video/(\d{6,12})(?![A-Za-z0-9_-])~i';

        foreach ([[$youtube, 'youtube'], [$youtubeShort, 'youtube'], [$vimeo, 'vimeo'], [$vimeoPlayer, 'vimeo']] as [$pattern, $provider]) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return ['provider' => $provider, 'id' => $matches[1], 'start' => $start];
            }
        }

        return null;
    }

    /**
     * The iframe source. Autoplay is OFF by default (a plain embed must not start playing on
     * page load); pass true for a click-to-play facade.
     */
    public static function embedUrl(string $provider, string $id, ?int $start = null, bool $autoplay = false): string
    {
        $params = $autoplay ? ['autoplay' => '1'] : [];

        if ($provider === 'vimeo') {
            $query = $params === [] ? '' : '?'.http_build_query($params);
            $fragment = $start !== null ? '#t='.$start.'s' : '';

            return 'https://player.vimeo.com/video/'.$id.$query.$fragment;
        }

        // rel=0 limits "related videos" to the same channel.
        $params['rel'] = '0';

        if ($start !== null) {
            $params['start'] = (string) $start;
        }

        return 'https://www.youtube-nocookie.com/embed/'.$id.'?'.http_build_query($params, '', '&');
    }

    /**
     * The clip on the provider's own site — a fallback for a visitor without JS.
     */
    public static function watchUrl(string $provider, string $id, ?int $start = null): string
    {
        if ($provider === 'vimeo') {
            return 'https://vimeo.com/'.$id.($start !== null ? '#t='.$start.'s' : '');
        }

        return 'https://www.youtube.com/watch?v='.$id.($start !== null ? '&t='.$start : '');
    }

    /**
     * Thumbnail candidates, best first: maxres does not exist for every clip (i.ytimg.com
     * answers 404), so the hqdefault fallback is required.
     *
     * @return list<string>
     */
    public static function thumbnailUrls(string $provider, string $id): array
    {
        if ($provider !== 'youtube') {
            return [];
        }

        return [
            'https://i.ytimg.com/vi/'.$id.'/maxresdefault.jpg',
            'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg',
        ];
    }

    /**
     * Vimeo has no deterministic thumbnail URL — only oEmbed returns it.
     */
    public static function oembedUrl(string $provider, string $id): ?string
    {
        if ($provider !== 'vimeo') {
            return null;
        }

        return 'https://vimeo.com/api/oembed.json?url='.rawurlencode('https://vimeo.com/'.$id);
    }

    /**
     * The timecode from `t=`/`start=`: plain seconds (`90`) and the `1h2m3s` format.
     */
    private static function parseStart(string $url): ?int
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);

        $raw = $params['start'] ?? $params['t'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw > 0 ? (int) $raw : null;
        }

        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $raw, $m) === 1) {
            $seconds = ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));

            return $seconds > 0 ? $seconds : null;
        }

        return null;
    }
}
