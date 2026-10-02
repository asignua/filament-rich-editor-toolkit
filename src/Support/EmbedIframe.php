<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

/**
 * The single shape a video takes in content: a bare `<iframe>` rebuilt from provider + id.
 * What the "Embed" button inserts and what {@see self::canonicalize()} rewrites legacy
 * pasted embeds into, so the site consumer sees one format wherever the tag came from.
 *
 * The attribute set is what YouTube's own "Share -> Embed" produces, plus `sandbox`.
 */
final class EmbedIframe
{
    /**
     * @return array<string, string>
     */
    public static function attributes(string $provider, string $id, ?int $start = null, ?string $hash = null): array
    {
        return [
            'src' => VideoEmbed::embedUrl($provider, $id, $start, hash: $hash),
            'width' => '560',
            'height' => '315',
            'title' => $provider === 'vimeo' ? 'Vimeo video' : 'YouTube video',
        ] + self::hardening();
    }

    /**
     * Attributes for an allow-listed non-video source.
     *
     * @return array<string, string>
     */
    public static function genericAttributes(string $src): array
    {
        return [
            'src' => $src,
            'width' => '560',
            'height' => '315',
        ] + self::hardening();
    }

    /**
     * The attributes forced onto EVERY embed on render, whatever the stored HTML said.
     *
     * @return array<string, string>
     */
    public static function hardening(): array
    {
        $attributes = [];

        $allow = config('rich-editor-toolkit.embed.allow');

        if (is_string($allow) && $allow !== '') {
            $attributes['allow'] = $allow;
        }

        $attributes['allowfullscreen'] = 'allowfullscreen';

        $sandbox = config('rich-editor-toolkit.embed.sandbox');

        if (is_string($sandbox) && $sandbox !== '') {
            $attributes['sandbox'] = $sandbox;
        }

        $policy = config('rich-editor-toolkit.embed.referrer_policy');

        if (is_string($policy) && $policy !== '') {
            $attributes['referrerpolicy'] = $policy;
        }

        if ((bool) config('rich-editor-toolkit.embed.lazy', true)) {
            $attributes['loading'] = 'lazy';
        }

        return $attributes;
    }

    /**
     * @internal not used by the toolkit; may change without a major release
     */
    public static function html(string $provider, string $id, ?int $start = null, ?string $hash = null): string
    {
        $attributes = '';

        foreach (self::attributes($provider, $id, $start, $hash) as $name => $value) {
            $attributes .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_HTML5).'"';
        }

        return '<iframe'.$attributes.'></iframe>';
    }

    /**
     * Rewrites every recognised video `<iframe>` in the HTML to {@see self::html()}; anything
     * else (a map, an unknown host) stays as it was.
     *
     * The order of the alternatives is deliberate: the self-closed `<iframe … />` first, then
     * an opening tag with its own `</iframe>` — the body must not cross the next `<iframe`,
     * otherwise an unclosed map tag before a video would swallow the video's closing tag and
     * the map's `src` would be checked — and only then a bare opening tag without a pair.
     *
     * @internal not used by the toolkit; may change without a major release
     */
    public static function canonicalize(string $html): string
    {
        if (stripos($html, '<iframe') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<iframe\b[^>]*\/>|<iframe\b[^>]*>(?:(?!<iframe\b).)*?<\/iframe>|<iframe\b[^>]*>/is',
            static function (array $match): string {
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $match[0], $src) !== 1) {
                    return $match[0];
                }

                $video = VideoEmbed::parse(html_entity_decode($src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return $video === null
                    ? $match[0]
                    : self::html($video['provider'], $video['id'], $video['start'], $video['hash']);
            },
            $html,
        );
    }
}
