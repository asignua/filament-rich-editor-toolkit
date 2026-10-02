<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\RichEditorToolkit;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

class RenderingTest extends TestCase
{
    public function test_stock_rendering_loses_the_attributes_which_is_why_the_toolkit_exists(): void
    {
        $html = RichContentRenderer::make('<p class="intro" id="top" data-track="hero">Hi</p>')->toHtml();

        $this->assertStringNotContainsString('id="top"', $html);
        $this->assertStringNotContainsString('data-track', $html);
    }

    public function test_class_id_and_style_survive_the_renderer(): void
    {
        $html = RichEditorToolkit::renderer('<p class="intro" id="top" style="margin: 0">Hi</p><h2 class="title">T</h2>')->toHtml();

        $this->assertStringContainsString('class="intro"', $html);
        $this->assertStringContainsString('id="top"', $html);
        $this->assertStringContainsString('margin: 0', $html);
        $this->assertStringContainsString('<h2 class="title"', $html);
    }

    public function test_configured_data_attributes_survive_and_unlisted_ones_do_not(): void
    {
        config()->set('rich-editor-toolkit.attributes', ['data-track', 'aria-label']);

        $html = RichEditorToolkit::renderer('<p data-track="hero" aria-label="Intro" data-other="x">Hi</p>')->toHtml();

        $this->assertStringContainsString('data-track="hero"', $html);
        $this->assertStringContainsString('aria-label="Intro"', $html);
        $this->assertStringNotContainsString('data-other', $html);
    }

    public function test_dangerous_attribute_names_are_never_accepted_even_when_configured(): void
    {
        config()->set('rich-editor-toolkit.attributes', [
            'onclick', 'href', 'srcdoc', 'data-ok',
            // Framework directives and URL-bearing attributes.
            'x-data', 'x-init', 'x-html', 'x-on:click', 'data-x-init', 'hx-get', 'data-hx-get', 'v-html', 'ng-click',
            'wire:click', 'is', 'srcset', 'formtarget', 'ping', 'background', 'poster', 'xmlns', 'xmlns:xlink', 'xml:lang',
            'aria-label', 'role',
        ]);

        $this->assertSame(['class', 'id', 'style', 'data-ok', 'aria-label', 'role'], RichEditorToolkit::attributeNames());

        $html = RichEditorToolkit::renderer('<p onclick="evil()" data-ok="1">Hi</p>')->toHtml();

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('data-ok="1"', $html);
    }

    public function test_runtime_registered_attributes_are_applied_even_after_the_sanitizer_was_built(): void
    {
        $first = RichEditorToolkit::renderer('<p data-late="1">Hi</p>')->toHtml();
        $this->assertStringNotContainsString('data-late', $first);

        RichEditorToolkit::allowAttributes(['data-late']);

        $this->assertStringContainsString('data-late="1"', RichEditorToolkit::renderer('<p data-late="1">Hi</p>')->toHtml());
    }

    public function test_legacy_divs_and_spans_keep_their_class_and_structure(): void
    {
        $html = RichEditorToolkit::renderer(
            '<div class="box"><p>block</p></div>'
            .'<div class="row"><img src="https://a.test/a.png"> <span>x</span></div>'
            .'<p>text <span class="hook">span</span></p>'
            .'<ul><li><div class="item">in li</div></li></ul>',
        )->toHtml();

        $this->assertStringContainsString('<div class="box"><p>block</p></div>', $html);
        $this->assertStringContainsString('<div class="row"><img', $html);
        $this->assertStringContainsString('<span class="hook">span</span>', $html);
        $this->assertStringContainsString('class="item"', $html);
    }

    public function test_images_keep_their_src_after_the_sanitizer_extension(): void
    {
        $html = RichEditorToolkit::renderer('<p><img src="https://a.test/a.png" width="10"></p>')->toHtml();

        $this->assertStringContainsString('src="https://a.test/a.png"', $html);
    }

    public function test_a_video_iframe_survives_with_forced_hardening(): void
    {
        $html = RichEditorToolkit::renderer(
            '<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0" width="560" height="315" sandbox="allow-top-navigation" allow="camera" title="Clip"></iframe>',
        )->toHtml();

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel&#61;0"', $html);
        $this->assertStringContainsString('title="Clip"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $html);
        $this->assertStringContainsString('allowfullscreen', $html);
        // The stored sandbox / allow are replaced by the configured ones.
        $this->assertStringNotContainsString('allow-top-navigation', $html);
        $this->assertStringNotContainsString('camera', $html);
        $this->assertStringContainsString('allow-presentation', $html);
    }

    public function test_foreign_and_scripted_iframes_are_dropped(): void
    {
        foreach ([
            '<iframe src="https://evil.test/x"></iframe>',
            '<iframe src="javascript:alert(1)"></iframe>',
            '<iframe src="http://player.vimeo.com/video/1"></iframe>',
            '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
        ] as $source) {
            $this->assertStringNotContainsString('<iframe', RichEditorToolkit::renderer($source)->toHtml(), $source);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedSources(): array
    {
        return [
            'foreign host' => ['https://evil.test/x'],
            'data url' => ['data:text/html,<script>alert(1)</script>'],
            'javascript' => ['javascript:alert(1)'],
            'traversal out of the prefix' => ['https://www.youtube-nocookie.com/embed/../../redirect'],
        ];
    }

    #[DataProvider('disallowedSources')]
    public function test_json_content_cannot_smuggle_a_disallowed_iframe(string $src): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]],
            ['type' => 'iframe', 'attrs' => ['src' => $src, 'sandbox' => null]],
        ]];

        // The node itself renders nothing (no sanitizer involved)...
        $unsafe = RichContentRenderer::make($document)->plugins(RichEditorToolkit::plugins())->toUnsafeHtml();
        $this->assertStringNotContainsString('<iframe', $unsafe);
        $this->assertStringContainsString('before', $unsafe);

        // ...and neither does the full pipeline.
        $this->assertStringNotContainsString('<iframe', RichEditorToolkit::renderer($document)->toHtml());
    }

    public function test_json_content_with_an_allowed_iframe_renders_hardened(): void
    {
        $html = RichEditorToolkit::renderer(['type' => 'doc', 'content' => [
            ['type' => 'iframe', 'attrs' => ['src' => 'https://player.vimeo.com/video/123456']],
        ]])->toHtml();

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('sandbox="', $html);
    }

    public function test_the_toolkit_sanitizer_drops_an_iframe_src_outside_the_allow_list_whatever_produced_it(): void
    {
        $sanitizer = RichEditorToolkit::sanitizer();

        $this->assertStringNotContainsString('evil.test', $sanitizer->sanitize('<iframe src="https://evil.test/x"></iframe>'));
        $this->assertStringNotContainsString('data:', $sanitizer->sanitize('<iframe src="data:text/html,x"></iframe>'));
        $this->assertStringContainsString('src="https://player.vimeo.com/video/123456"', $sanitizer->sanitize('<iframe src="https://player.vimeo.com/video/123456"></iframe>'));
        $this->assertStringContainsString('src="https://a.test/a.png"', $sanitizer->sanitize('<img src="https://a.test/a.png">'));
    }

    public function test_filaments_shared_sanitizer_is_never_extended(): void
    {
        config()->set('rich-editor-toolkit.attributes', ['data-track']);
        RichEditorToolkit::allowAttributes(['data-late']);

        // Build everything that used to switch the global allowances on.
        EmbedPlugin::make();
        CustomAttributesPlugin::make()->attributes(['data-instance']);
        RichEditorToolkit::renderer('<iframe src="https://player.vimeo.com/video/123456"></iframe>')->toHtml();

        foreach ([
            app(HtmlSanitizerInterface::class)->sanitize('<iframe src="https://player.vimeo.com/video/123456"></iframe><p data-track="1" data-late="1" data-instance="1">x</p>'),
            Str::sanitizeHtml('<iframe src="https://evil.test/x" sandbox="allow-top-navigation"></iframe><p data-track="1">x</p>'),
        ] as $html) {
            $this->assertStringNotContainsString('<iframe', $html);
            $this->assertStringNotContainsString('data-track', $html);
            $this->assertStringNotContainsString('data-late', $html);
            $this->assertStringNotContainsString('data-instance', $html);
        }
    }

    public function test_a_renderer_without_the_embed_plugin_keeps_no_iframe(): void
    {
        $renderer = RichContentRenderer::make('<p data-track="1">x</p>')
            ->plugins([CustomAttributesPlugin::make()->attributes(['data-track'])]);

        $this->assertStringContainsString('data-track="1"', RichEditorToolkit::toHtml($renderer));
        $this->assertStringNotContainsString('<iframe', RichEditorToolkit::sanitizer(embeds: false)->sanitize('<iframe src="https://player.vimeo.com/video/123456"></iframe>'));
    }

    public function test_per_instance_attributes_and_types_can_be_given_to_the_renderer(): void
    {
        // A fresh request: no form plugin was ever built, only the renderer.
        $html = RichEditorToolkit::renderer('<p data-instance="1" data-other="2">x</p>', attributes: ['data-instance'])->toHtml();

        $this->assertStringContainsString('data-instance="1"', $html);
        $this->assertStringNotContainsString('data-other', $html);

        $plugin = RichEditorToolkit::plugins(types: ['myNode'])[0];
        $this->assertInstanceOf(CustomAttributesPlugin::class, $plugin);
        $this->assertContains('myNode', $plugin->getTypes());
    }

    public function test_a_plugin_instance_list_does_not_leak_into_the_defaults(): void
    {
        CustomAttributesPlugin::make()->attributes(['data-instance']);

        $this->assertNotContains('data-instance', RichEditorToolkit::attributeNames());
        $this->assertStringNotContainsString('data-instance', RichEditorToolkit::renderer('<p data-instance="1">x</p>')->toHtml());
    }

    public function test_an_allow_listed_host_works_from_the_config(): void
    {
        config()->set('rich-editor-toolkit.embed.hosts', ['www.google.com/maps/embed']);

        $html = RichEditorToolkit::renderer('<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>')->toHtml();

        $this->assertStringContainsString('google.com/maps/embed?pb&#61;1', $html);
    }

    public function test_sanitizer_keeps_script_tags_out_regardless(): void
    {
        $html = RichEditorToolkit::renderer('<p>x<script>alert(1)</script></p>')->toHtml();

        $this->assertStringNotContainsString('<script', $html);
    }
}
