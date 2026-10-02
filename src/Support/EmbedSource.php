<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

/**
 * The allow-list of iframe sources.
 *
 * Rich content reaches the public site as stored HTML, and the check in the "Embed" modal
 * cannot see what was pasted or typed in the source view. So the SAME rule runs when the
 * HTML is parsed back into the document (see {@see \Asignua\RichEditorToolkit\Extensions\IframeNode}):
 * an iframe whose `src` fails it does not exist as a node and is dropped.
 *
 * Entries are `host` or `host/path/prefix`; https only, no credentials in the URL, exact host
 * match (never a suffix match: `player.vimeo.com.evil.test` must fail).
 *
 * The path is compared RAW, so anything a browser would rewrite before requesting it is
 * refused outright: dot segments (`/maps/embed/../../url` resolves to `/url`, an open
 * redirect on google.com), their encoded forms (`%2e`), encoded slashes and backslashes. A
 * prefix matches on a segment boundary only: `maps/embed` accepts `/maps/embed`,
 * `/maps/embed/x` and `/maps/embed?pb=…`, never `/maps/embedded`.
 */
final class EmbedSource
{
    /** Built-in entries: the hosts {@see VideoEmbed::embedUrl()} can produce. */
    public const array BUILT_IN = [
        'www.youtube-nocookie.com/embed/',
        'player.vimeo.com/video/',
    ];

    /**
     * @return list<string>
     */
    public static function entries(): array
    {
        /** @var array<mixed> $configured */
        $configured = (array) config('rich-editor-toolkit.embed.hosts', []);

        $entries = self::BUILT_IN;

        foreach ($configured as $entry) {
            if (is_string($entry) && self::isWellFormedEntry($entry)) {
                $entries[] = strtolower(trim($entry));
            }
        }

        return array_values(array_unique($entries));
    }

    public static function allows(?string $src): bool
    {
        $src = trim((string) $src);

        if ($src === '' || preg_match('/[\x00-\x20]/', $src) === 1) {
            return false;
        }

        $parts = parse_url($src);

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || !isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        if (preg_match('~(^|/)\.\.?(/|$)|%2e|%2f|%5c|\\\\~i', $path) === 1) {
            return false;
        }

        foreach (self::entries() as $entry) {
            $slash = strpos($entry, '/');
            $entryHost = $slash === false ? $entry : substr($entry, 0, $slash);
            $entryPath = $slash === false ? '' : substr($entry, $slash);

            if ($host === $entryHost && self::pathMatches($path, $entryPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `''` matches every path; an entry ending in `/` is a directory prefix; any other entry
     * matches itself exactly or as a parent segment.
     */
    private static function pathMatches(string $path, string $entryPath): bool
    {
        if ($entryPath === '' || $path === $entryPath) {
            return true;
        }

        $prefix = str_ends_with($entryPath, '/') ? $entryPath : $entryPath.'/';

        return str_starts_with($path, $prefix);
    }

    private static function isWellFormedEntry(string $entry): bool
    {
        return preg_match('#^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(/[A-Za-z0-9._~/%-]*)?$#i', trim($entry)) === 1;
    }
}
