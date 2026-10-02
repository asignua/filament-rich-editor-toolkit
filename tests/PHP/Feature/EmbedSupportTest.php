<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Support\EmbedIframe;
use Asignua\RichEditorToolkit\Support\EmbedSource;
use Asignua\RichEditorToolkit\Tests\TestCase;

class EmbedSupportTest extends TestCase
{
    public function test_the_built_in_hosts_are_allowed_over_https_only(): void
    {
        $this->assertTrue(EmbedSource::allows('https://www.youtube-nocookie.com/embed/abcdef?rel=0'));
        $this->assertTrue(EmbedSource::allows('https://player.vimeo.com/video/123456'));
        $this->assertFalse(EmbedSource::allows('http://player.vimeo.com/video/123456'));
        $this->assertFalse(EmbedSource::allows('//player.vimeo.com/video/123456'));
        $this->assertFalse(EmbedSource::allows('https://www.youtube-nocookie.com/watch?v=abcdef'));
        $this->assertFalse(EmbedSource::allows('https://player.vimeo.com.evil.test/video/1'));
        $this->assertFalse(EmbedSource::allows('https://user:pass@player.vimeo.com/video/1'));
        $this->assertFalse(EmbedSource::allows("https://player.vimeo.com/video/1\n.evil"));
        $this->assertFalse(EmbedSource::allows('javascript:alert(1)'));
        $this->assertFalse(EmbedSource::allows(null));
    }

    public function test_configured_hosts_extend_the_list_and_bad_entries_are_ignored(): void
    {
        config()->set('rich-editor-toolkit.embed.hosts', ['www.google.com/maps/embed', 'bad host/with space', 42]);

        $this->assertTrue(EmbedSource::allows('https://www.google.com/maps/embed?pb=1'));
        $this->assertFalse(EmbedSource::allows('https://www.google.com/search?q=1'));
        $this->assertSame(['www.youtube-nocookie.com/embed/', 'player.vimeo.com/video/', 'www.google.com/maps/embed'], EmbedSource::entries());
    }

    public function test_hardening_follows_the_config(): void
    {
        $hardening = EmbedIframe::hardening();

        $this->assertStringContainsString('allow-scripts', $hardening['sandbox']);
        $this->assertSame('lazy', $hardening['loading']);
        $this->assertSame('strict-origin-when-cross-origin', $hardening['referrerpolicy']);

        config()->set('rich-editor-toolkit.embed.sandbox', null);
        config()->set('rich-editor-toolkit.embed.lazy', false);

        $this->assertArrayNotHasKey('sandbox', EmbedIframe::hardening());
        $this->assertArrayNotHasKey('loading', EmbedIframe::hardening());
    }

    public function test_canonicalize_rewrites_video_iframes_and_leaves_the_rest(): void
    {
        $html = '<p>x</p><iframe width="1" src="https://youtu.be/dQw4w9WgXcQ?t=5"></iframe>'
            .'<iframe src="https://maps.example.test/m"></iframe>';

        $out = EmbedIframe::canonicalize($html);

        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&amp;start=5"', $out);
        $this->assertStringContainsString('sandbox=', $out);
        $this->assertStringContainsString('<iframe src="https://maps.example.test/m"></iframe>', $out);
        $this->assertSame('<p>no iframe</p>', EmbedIframe::canonicalize('<p>no iframe</p>'));
    }

    public function test_an_unclosed_iframe_does_not_swallow_the_next_video(): void
    {
        $out = EmbedIframe::canonicalize('<iframe src="https://maps.example.test/m"><iframe src="https://youtu.be/dQw4w9WgXcQ"></iframe>');

        $this->assertStringContainsString('<iframe src="https://maps.example.test/m">', $out);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $out);
    }

    public function test_the_plugin_builds_attributes_for_a_video_an_allowed_host_and_nothing_else(): void
    {
        config()->set('rich-editor-toolkit.embed.hosts', ['maps.example.test']);

        $video = EmbedPlugin::attributesFor(' https://youtu.be/dQw4w9WgXcQ ');
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0', $video['src'] ?? null);
        $this->assertSame('YouTube video', $video['title'] ?? null);

        $this->assertSame('https://maps.example.test/x', EmbedPlugin::attributesFor('https://maps.example.test/x')['src'] ?? null);
        $this->assertNull(EmbedPlugin::attributesFor('https://evil.test/x'));
        $this->assertNull(EmbedPlugin::attributesFor(''));
    }
}
