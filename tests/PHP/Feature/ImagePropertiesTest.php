<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Extensions\CustomAttributes;
use Asignua\RichEditorToolkit\Extensions\ImageProperties;
use Asignua\RichEditorToolkit\Plugins\ImagePropertiesPlugin;
use Asignua\RichEditorToolkit\RichEditorToolkit;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension;
use Livewire\Livewire;
use Tiptap\Editor;
use Workbench\App\Livewire\DemoForm;

class ImagePropertiesTest extends TestCase
{
    private function editor(): Editor
    {
        return new Editor([
            'extensions' => [
                new ImageProperties,
                new CustomAttributes,
                ...array_map(static fn (string $class): object => new $class, [
                    \Tiptap\Nodes\Document::class,
                    \Tiptap\Nodes\Paragraph::class,
                    \Tiptap\Nodes\Text::class,
                ]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstImage(string $html): array
    {
        $document = $this->editor()->setContent($html)->getDocument();

        $this->assertIsArray($document);

        $found = null;
        $walk = function (array $nodes) use (&$walk, &$found): void {
            foreach ($nodes as $node) {
                if (($node['type'] ?? null) === 'image' && $found === null) {
                    $found = $node;
                }

                $walk($node['content'] ?? []);
            }
        };
        $walk($document['content'] ?? []);

        $this->assertNotNull($found, 'no image node in '.json_encode($document));

        return $found;
    }

    private function render(string $html): string
    {
        return RichEditorToolkit::renderer($html)->toHtml();
    }

    public function test_a_plain_image_renders_exactly_as_before(): void
    {
        $stock = RichContentRenderer::make('<p><img src="https://cdn.test/a.png" alt="A cat" width="300" title="T"></p>')->toHtml();

        $this->assertSame($stock, $this->render('<p><img src="https://cdn.test/a.png" alt="A cat" width="300" title="T"></p>'));
        $this->assertStringNotContainsString('asignua-rte', $this->render('<p><img src="https://cdn.test/a.png" alt="A cat"></p>'));
    }

    public function test_a_missing_alt_stays_missing_and_an_empty_alt_is_kept(): void
    {
        $missing = $this->render('<p><img src="https://cdn.test/a.png"></p>');
        $decorative = $this->render('<p><img src="https://cdn.test/a.png" alt=""></p>');

        $this->assertStringNotContainsString('alt', $missing);
        // Symfony's serialiser writes an empty attribute as a bare `alt`, which HTML reads as alt="".
        $this->assertMatchesRegularExpression('#<img [^>]*\balt(=""| ?/>)#', $decorative);
        $this->assertStringContainsString('src="https://cdn.test/a.png"', $decorative);

        $this->assertArrayNotHasKey('alt', $this->firstImage('<p><img src="https://cdn.test/a.png"></p>')['attrs']);
        $this->assertSame('', $this->firstImage('<p><img src="https://cdn.test/a.png" alt=""></p>')['attrs']['alt']);
    }

    public function test_caption_and_alignment_render_as_an_inline_safe_wrapper(): void
    {
        $html = $this->render('<p><img src="https://cdn.test/a.png" alt="A cat" class="rounded"></p>');
        $this->assertStringNotContainsString('asignua-rte', $html);

        $document = $this->editor()->setContent('<p><img src="https://cdn.test/a.png" alt="A cat" class="rounded"></p>')->getDocument();
        $document['content'][0]['content'][0]['attrs']['caption'] = 'Tom, 2024';
        $document['content'][0]['content'][0]['attrs']['align'] = 'center';

        $out = RichEditorToolkit::renderer($document)->toHtml();

        $this->assertStringContainsString('<span class="asignua-rte-figure asignua-rte-align-center">', $out);
        $this->assertStringContainsString('<span class="asignua-rte-caption">Tom, 2024</span>', $out);
        $this->assertStringContainsString('alt="A cat"', $out);
        $this->assertStringContainsString('class="rounded"', $out, 'the author class stays on the <img>');
        $this->assertStringNotContainsString('<figure', $out, 'a <figure> inside a <p> is invalid');
        $this->assertMatchesRegularExpression('#^<p><span class="asignua-rte-figure[^"]*"><img #', $out);
    }

    public function test_the_wrapper_round_trips_through_the_server_schema(): void
    {
        $once = $this->render('<p><span class="asignua-rte-figure asignua-rte-align-right"><img src="https://cdn.test/a.png" alt="A cat" data-id="k1"><span class="asignua-rte-caption">Caption</span></span></p>');

        $this->assertSame($once, $this->render($once));

        $image = $this->firstImage($once);

        $this->assertSame('Caption', $image['attrs']['caption']);
        $this->assertSame('right', $image['attrs']['align']);
        $this->assertSame('k1', $image['attrs']['id']);
        $this->assertArrayNotHasKey('content', $image, 'nothing of the wrapper may leak into the document JSON');
    }

    public function test_an_image_node_the_application_rebound_is_kept_not_shadowed(): void
    {
        // What a media manager / a site does: rebind Filament's image node (src from data-id,
        // lazy loading, a default alt). The shadow must render THROUGH it, not past it.
        $this->app->bind(ImageExtension::class, fn (): ImageExtension => new class extends ImageExtension
        {
            public function renderHTML($node, $HTMLAttributes = []): array
            {
                $HTMLAttributes['src'] = 'https://library.test/'.($node->attrs->id ?? 'x').'.png';
                $HTMLAttributes['loading'] = 'lazy';
                $HTMLAttributes['alt'] ??= 'Library alt';

                return parent::renderHTML($node, $HTMLAttributes);
            }
        });

        $plain = $this->render('<p><img src="/old.png" data-id="7" alt="A"></p>');
        $this->assertStringContainsString('src="https://library.test/7.png"', $plain);
        $this->assertStringContainsString('loading="lazy"', $plain);

        $captioned = $this->render('<p><span class="asignua-rte-figure asignua-rte-align-left"><img src="/old.png" data-id="7" alt="A"><span class="asignua-rte-caption">Cap</span></span></p>');
        $this->assertStringContainsString('<span class="asignua-rte-figure asignua-rte-align-left"><img', $captioned);
        $this->assertStringContainsString('src="https://library.test/7.png"', $captioned);
        $this->assertStringContainsString('loading="lazy"', $captioned);
        $this->assertStringContainsString('<span class="asignua-rte-caption">Cap</span></span>', $captioned);

        // A decorative image stays decorative even when the bound node fills in a default alt.
        $decorative = $this->render('<p><img src="/old.png" data-id="7" alt=""></p>');
        $this->assertMatchesRegularExpression('#<img [^>]*\\balt(=""| ?/>)#', $decorative);
        $this->assertStringNotContainsString('Library alt', $decorative);
    }

    public function test_a_figure_typed_in_the_source_view_is_read_and_rewritten(): void
    {
        $image = $this->firstImage('<figure class="wp-block-image align-x asignua-rte-align-center" style="margin:0"><img src="https://cdn.test/a.png" alt="" class="photo"><figcaption>  Two   <b>lines</b>
 of text </figcaption></figure>');

        $this->assertSame('center', $image['attrs']['align']);
        $this->assertSame('Two lines of text', $image['attrs']['caption']);
        $this->assertSame('', $image['attrs']['alt']);
        $this->assertArrayNotHasKey('content', $image);

        $out = $this->render('<figure data-align="left"><img src="https://cdn.test/a.png" alt="A"></figure>');

        $this->assertStringContainsString('<span class="asignua-rte-figure asignua-rte-align-left"><img', $out);
        $this->assertStringNotContainsString('figcaption', $out);
        $this->assertStringNotContainsString('asignua-rte-caption', $out);
    }

    public function test_a_figure_around_something_else_is_left_alone(): void
    {
        $out = $this->render('<figure><img src="https://cdn.test/a.png"><img src="https://cdn.test/b.png"></figure><figure><blockquote><p>Quote</p></blockquote><figcaption>Who</figcaption></figure>');

        $this->assertStringNotContainsString('asignua-rte-figure', $out);
    }

    public function test_an_unknown_alignment_is_never_a_class(): void
    {
        $document = $this->editor()->setContent('<p><img src="https://cdn.test/a.png"></p>')->getDocument();
        $document['content'][0]['content'][0]['attrs']['align'] = 'center" onclick="alert(1)';
        $document['content'][0]['content'][0]['attrs']['caption'] = '   ';

        $out = RichEditorToolkit::renderer($document)->toHtml();

        $this->assertStringNotContainsString('asignua-rte', $out);
        $this->assertStringNotContainsString('onclick', $out);

        $this->assertSame('left', $this->firstImage('<figure class="asignua-rte-align-left"><img src="https://cdn.test/a.png"></figure>')['attrs']['align']);
        $this->assertArrayNotHasKey('align', $this->firstImage('<figure class="asignua-rte-align-evil"><img src="https://cdn.test/a.png"></figure>')['attrs']);
    }

    public function test_html_in_the_caption_and_the_alt_is_escaped(): void
    {
        $document = $this->editor()->setContent('<p><img src="https://cdn.test/a.png"></p>')->getDocument();
        $document['content'][0]['content'][0]['attrs']['alt'] = '"><script>alert(1)</script>';
        $document['content'][0]['content'][0]['attrs']['caption'] = '<img src=x onerror=alert(1)><script>alert(2)</script> & "q"';
        $document['content'][0]['content'][0]['attrs']['align'] = 'center';

        $out = RichEditorToolkit::renderer($document)->toHtml();

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<img src=x', $out);
        $this->assertStringNotContainsString('onerror=alert(1)>', $out);
        $this->assertStringContainsString('&lt;img src', $out);
        $this->assertStringContainsString('&amp;', $out);
        $this->assertSame(1, substr_count($out, '<img '), 'the only <img> is the image itself');

        // And the same through the unsanitised path the renderer feeds the sanitiser with.
        $unsafe = RichEditorToolkit::renderer($document)->toUnsafeHtml();
        $this->assertStringNotContainsString('<script', $unsafe);
    }

    public function test_a_caption_in_a_caption_span_survives_the_sanitiser_but_a_foreign_class_does_not_matter(): void
    {
        $out = $this->render('<p><span class="asignua-rte-figure"><img src="https://cdn.test/a.png" alt="x"><span class="asignua-rte-caption">C</span></span></p>');

        $this->assertStringContainsString('<span class="asignua-rte-caption">C</span>', $out);
    }

    public function test_custom_attributes_stay_on_the_image_not_the_wrapper(): void
    {
        config()->set('rich-editor-toolkit.attributes', ['data-track']);

        $out = $this->render('<figure class="asignua-rte-align-center" data-track="wrapper"><img src="https://cdn.test/a.png" class="photo" style="border:0" data-track="img"><figcaption>C</figcaption></figure>');

        $this->assertStringContainsString('<span class="asignua-rte-figure asignua-rte-align-center"><img', $out);
        $this->assertStringContainsString('class="photo"', $out);
        $this->assertStringContainsString('data-track="img"', $out);
        $this->assertStringNotContainsString('wrapper', $out);
        $this->assertSame(1, substr_count($out, 'class="photo"'));
    }

    public function test_the_plugin_is_part_of_the_front_end_renderer(): void
    {
        $classes = array_map(static fn (object $plugin): string => $plugin::class, RichEditorToolkit::plugins());

        $this->assertContains(ImagePropertiesPlugin::class, $classes);
    }

    public function test_the_tool_is_disabled_while_no_image_is_selected_and_reads_the_image_attributes(): void
    {
        $tool = ImagePropertiesPlugin::make()->getEditorTools()[0];

        $this->assertSame('imageProperties', $tool->getName());
        $this->assertTrue($tool->isDisabledWhenNotActive());
        $this->assertSame('image', $tool->getActiveKey());

        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $html = html_entity_decode(Livewire::test(DemoForm::class)->html(), ENT_QUOTES);

        // Filament 5.9 renders `$wire.mountAction(...)`, 5.10 `mountToolAction(...)`.
        $this->assertMatchesRegularExpression("/mount(?:Tool)?Action\\('imageProperties', \\{ editorSelection, \\.\\.\\.\\{ alt: /", $html);

        foreach (['decorative:', 'caption:', 'align:'] as $key) {
            $this->assertStringContainsString($key, $html);
        }
    }

    public function test_the_plugin_ships_the_php_node_and_a_module_with_the_stylesheet(): void
    {
        $plugin = ImagePropertiesPlugin::make();

        $this->assertInstanceOf(ImageProperties::class, $plugin->getTipTapPhpExtensions()[0]);
        $this->assertStringContainsString('image-properties.js', $plugin->getTipTapJsExtensions()[0]);
        $this->assertStringContainsString('css=', $plugin->getTipTapJsExtensions()[0]);
    }

    public function test_the_dialog_maps_its_fields_to_node_attributes(): void
    {
        $this->assertSame(['alt' => '', 'caption' => null, 'align' => null], ImagePropertiesPlugin::attributesFrom(['decorative' => true, 'alt' => 'ignored', 'align' => 'none']));
        $this->assertSame(['alt' => 'A cat', 'caption' => 'Tom', 'align' => 'left'], ImagePropertiesPlugin::attributesFrom(['decorative' => false, 'alt' => ' A cat ', 'caption' => " Tom \n", 'align' => 'left']));
        $this->assertSame(['alt' => null, 'caption' => null, 'align' => null], ImagePropertiesPlugin::attributesFrom(['alt' => '   ', 'align' => 'diagonal']));
    }

    public function test_the_modal_prefills_from_the_image_and_updates_the_selected_node(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $arguments = ['editorSelection' => ['type' => 'node', 'anchor' => 4], 'alt' => 'Old', 'decorative' => false, 'caption' => 'Cap', 'align' => 'right'];

        Livewire::test(DemoForm::class)
            ->mountAction(TestAction::make('imageProperties')->schemaComponent('body')->arguments($arguments))
            ->assertSchemaStateSet(['decorative' => false, 'alt' => 'Old', 'caption' => 'Cap', 'align' => 'right']);

        Livewire::test(DemoForm::class)
            ->callAction(TestAction::make('imageProperties')->schemaComponent('body')->arguments($arguments), ['decorative' => false, 'alt' => 'New', 'caption' => 'Updated', 'align' => 'center'])
            ->assertHasNoFormErrors()
            ->assertDispatched('run-rich-editor-commands', function (string $event, array $params): bool {
                $command = $params['commands'][0] ?? [];

                return ($command['name'] ?? null) === 'updateAttributes'
                    && ($command['arguments'] ?? null) === ['image', ['alt' => 'New', 'caption' => 'Updated', 'align' => 'center']]
                    && ($params['editorSelection']['type'] ?? null) === 'node'
                    && ($params['editorSelection']['anchor'] ?? null) === 4;
            });
    }

    public function test_a_text_selection_after_the_image_is_turned_into_the_node_selection(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        Livewire::test(DemoForm::class)
            ->callAction(
                TestAction::make('imageProperties')->schemaComponent('body')->arguments(['editorSelection' => ['type' => 'text', 'anchor' => 5, 'head' => 5]]),
                ['decorative' => true, 'align' => 'none'],
            )
            ->assertDispatched('run-rich-editor-commands', fn (string $event, array $params): bool => ($params['editorSelection'] ?? null) === ['type' => 'node', 'anchor' => 4]
                && ($params['commands'][0]['arguments'] ?? null) === ['image', ['alt' => '', 'caption' => null, 'align' => null]]);
    }

    public function test_the_default_floating_toolbars_can_carry_the_button(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $html = Livewire::test(DemoForm::class)->html();

        $this->assertStringContainsString('floatingToolbar::image', $html);
        $this->assertStringContainsString('floatingToolbar::table', $html);
        $this->assertStringContainsString('image-properties.js', $html);
    }

    public function test_the_php_and_js_constants_agree(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/src/image-properties.js');

        $this->assertStringContainsString("FIGURE_CLASS = '".ImageProperties::FIGURE_CLASS."'", $js);
        $this->assertStringContainsString("CAPTION_CLASS = '".ImageProperties::CAPTION_CLASS."'", $js);
        $this->assertStringContainsString("ALIGN_CLASS_PREFIX = '".ImageProperties::ALIGN_CLASS_PREFIX."'", $js);
        $this->assertStringContainsString("ALIGNMENTS = ['".implode("', '", ImageProperties::ALIGNMENTS)."']", $js);
        $this->assertStringContainsString("STASH_ALIGN = 'data-asignua-align'", $js);
        $this->assertStringContainsString("STASH_CAPTION = 'data-asignua-caption'", $js);
        $this->assertStringContainsString("'data-asignua-adopted'", $js);
    }

    public function test_the_stylesheets_cover_every_class_the_markup_uses(): void
    {
        $root = dirname(__DIR__, 3);
        $editor = (string) file_get_contents($root.'/resources/dist/filament-rich-editor-toolkit-ui.css');
        $front = (string) file_get_contents($root.'/resources/css/image-properties.css');

        foreach (['.'.ImageProperties::FIGURE_CLASS, '.'.ImageProperties::CAPTION_CLASS, ...array_map(static fn (string $align): string => '.'.ImageProperties::ALIGN_CLASS_PREFIX.$align, ImageProperties::ALIGNMENTS)] as $selector) {
            $this->assertStringContainsString($selector.' {', $editor, $selector.' in the editor stylesheet');
            $this->assertStringContainsString($selector.' {', $front, $selector.' in the front stylesheet');
        }

        $this->assertStringContainsString('data-asignua-caption', $editor);
        $this->assertStringContainsString('.dark .fi-fo-rich-editor .asignua-rte-figure[data-asignua-caption]', $editor);
    }
}
