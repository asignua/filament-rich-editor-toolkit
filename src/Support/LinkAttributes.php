<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

/**
 * The `target` and `rel` of links, and the one place that decides what they may be.
 *
 *  - `target` is either `_blank` or absent. `_self`, `_parent`, `_top`, a frame name: all dropped.
 *  - `rel` is a list of tokens, and only the tokens of the whitelist survive
 *    (`link_options.rel_tokens`: the HTML link types without `opener` by default).
 *  - a link that opens a new tab ALWAYS carries `noopener noreferrer`, whatever the stored HTML
 *    said. (Browsers imply `noopener` for `_blank` today; the explicit token costs nothing and
 *    keeps old browsers, crawlers and audits happy.)
 *  - `link_options.external_rel` tokens (e.g. `nofollow`) are added to absolute links whose host is
 *    not the site's own, at RENDER time only: the stored content stays what the editor wrote.
 *
 * {@see self::apply()} runs over the markup that Symfony's sanitizer produced, i.e. canonical
 * HTML in which every attribute value is entity-encoded (a `>` or `"` cannot occur inside one),
 * which is what makes a tag-level pass safe here. It is applied by
 * {@see \Asignua\RichEditorToolkit\RichEditorToolkit::toHtml()} when a `LinkOptionsPlugin` is attached
 * to the renderer, and it does not touch anything else.
 */
final class LinkAttributes
{
    /** Tokens an editor can switch on in the dialog. */
    public const array EDITABLE = ['nofollow', 'sponsored', 'ugc'];

    /** Added to every `_blank` link. */
    public const array FOR_NEW_TAB = ['noopener', 'noreferrer'];

    /**
     * What the rel whitelist is when nothing is configured: the HTML link types that are safe to
     * keep, so that a `rel="me"` (Mastodon verification) or `rel="author"` an editor typed in the
     * source view is not lost. `opener` is not among them: it asks for the opposite of what a new
     * tab is forced to do.
     */
    public const array DEFAULT_TOKENS = [
        'alternate', 'author', 'bookmark', 'external', 'help', 'license', 'me', 'next', 'nofollow', 'noopener',
        'noreferrer', 'prev', 'privacy-policy', 'search', 'sponsored', 'tag', 'terms-of-service', 'ugc',
    ];

    /**
     * The configured rel whitelist.
     *
     * @return list<string>
     */
    public static function allowedTokens(): array
    {
        /** @var array<mixed> $configured */
        $configured = (array) config('rich-editor-toolkit.link_options.rel_tokens', self::DEFAULT_TOKENS);

        return self::tokens($configured);
    }

    /**
     * `_blank` or null.
     */
    public static function normalizeTarget(mixed $target): ?string
    {
        return is_string($target) && strtolower(trim($target)) === '_blank' ? '_blank' : null;
    }

    /**
     * The rel to write: the whitelisted tokens of `$rel`, then `$extra`, then `noopener noreferrer`
     * for a new tab. Null when nothing is left.
     *
     * @param list<string> $extra
     */
    public static function normalizeRel(mixed $rel, bool $newTab, array $extra = []): ?string
    {
        $allowed = self::allowedTokens();
        $wanted = [
            ...self::tokens(is_string($rel) ? preg_split('/\s+/', $rel) ?: [] : []),
            ...self::tokens($extra),
        ];

        $kept = array_values(array_filter($wanted, static fn (string $token): bool => in_array($token, $allowed, true)));

        if ($newTab) {
            $kept = [...$kept, ...self::FOR_NEW_TAB];
        }

        $kept = array_values(array_unique($kept));

        return $kept === [] ? null : implode(' ', $kept);
    }

    /**
     * Whether an href points at another site: an absolute http(s) or protocol-relative URL whose
     * host is not one of `$internalHosts` (compared without a leading `www.`).
     *
     * @param list<string> $internalHosts
     */
    public static function isExternal(string $href, array $internalHosts): bool
    {
        $href = trim($href);

        if (preg_match('~^(?:https?:)?//~i', $href) !== 1) {
            return false;
        }

        $host = parse_url(str_starts_with($href, '//') ? 'https:'.$href : $href, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = self::bareHost($host);

        foreach ($internalHosts as $internal) {
            if ($host === self::bareHost($internal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalises every `<a>` of an already-sanitized HTML string. Anchors keep their other
     * attributes byte for byte; `target` and `rel` are removed and, when they have a value, written
     * back at the end.
     *
     * @param list<string> $externalRel   tokens for external links (render-time)
     * @param list<string> $internalHosts
     */
    public static function apply(string $html, array $externalRel = [], array $internalHosts = []): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }

        return (string) preg_replace_callback('/<a(\s[^<>]*)?>/i', static function (array $match) use ($externalRel, $internalHosts): string {
            $attributes = [];
            $kept = '';

            preg_match_all('/\s+([^\s=\/>"\']+)(?:="([^"]*)")?/', $match[1] ?? '', $found, PREG_SET_ORDER);

            foreach ($found as $attribute) {
                $name = strtolower($attribute[1]);
                $value = html_entity_decode($attribute[2] ?? '', ENT_QUOTES | ENT_HTML5);

                if ($name === 'target' || $name === 'rel') {
                    $attributes[$name] = $value;

                    continue;
                }

                $kept .= $attribute[0];

                if ($name === 'href') {
                    $attributes['href'] = $value;
                }
            }

            $target = self::normalizeTarget($attributes['target'] ?? null);
            $external = isset($attributes['href']) && $externalRel !== [] && self::isExternal($attributes['href'], $internalHosts);
            $rel = self::normalizeRel($attributes['rel'] ?? null, $target !== null, $external ? $externalRel : []);

            return '<a'.$kept
                .($target !== null ? ' target="'.$target.'"' : '')
                .($rel !== null ? ' rel="'.$rel.'"' : '')
                .'>';
        }, $html);
    }

    /**
     * Lower-cased, well-formed rel tokens, de-duplicated.
     *
     * @param array<mixed> $tokens
     *
     * @return list<string>
     */
    private static function tokens(array $tokens): array
    {
        $clean = [];

        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $token = strtolower(trim($token));

            if (preg_match('/^[a-z][a-z-]*$/', $token) === 1) {
                $clean[] = $token;
            }
        }

        return array_values(array_unique($clean));
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
