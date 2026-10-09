<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Extensions\CustomAttributes;
use Asignua\RichEditorToolkit\Extensions\CustomDivInline;
use Asignua\RichEditorToolkit\Extensions\CustomSpan;
use Asignua\RichEditorToolkit\Extensions\IframeNode;
use Asignua\RichEditorToolkit\Plugins\CharacterCountPlugin;
use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Plugins\FullscreenPlugin;
use Asignua\RichEditorToolkit\Plugins\ImagePropertiesPlugin;
use Asignua\RichEditorToolkit\Plugins\ImageUrlPlugin;
use Asignua\RichEditorToolkit\Plugins\PasteCleanPlugin;
use Asignua\RichEditorToolkit\Plugins\SourceCodePlugin;
use Asignua\RichEditorToolkit\Tests\TestCase;
use DOMDocument;
use DOMElement;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;
use ReflectionClassConstant;
use Workbench\App\Livewire\DemoForm;

class PluginsTest extends TestCase
{
    public function test_every_plugin_implements_the_filament_contract(): void
    {
        foreach ([PasteCleanPlugin::class, CustomAttributesPlugin::class, EmbedPlugin::class, ImageUrlPlugin::class, FullscreenPlugin::class, CharacterCountPlugin::class, SourceCodePlugin::class, ImagePropertiesPlugin::class] as $class) {
            $this->assertInstanceOf(RichContentPlugin::class, $class::make());
        }
    }

    public function test_paste_clean_has_no_server_half_and_one_tool(): void
    {
        $plugin = PasteCleanPlugin::make();

        $this->assertSame([], $plugin->getTipTapPhpExtensions());
        $this->assertSame(['cleanFormat'], array_map(fn ($tool): string => $tool->getName(), $plugin->getEditorTools()));
        $this->assertStringContainsString('asignuaCleanFormat', (string) $plugin->getEditorTools()[0]->getJsHandler());
    }

    public function test_paste_clean_passes_the_configured_link_prefixes_to_the_module(): void
    {
        $this->assertStringNotContainsString('keep=', PasteCleanPlugin::make()->getTipTapJsExtensions()[0]);

        config()->set('rich-editor-toolkit.paste_clean.keep_link_prefixes', ['/internal/', '']);

        $url = PasteCleanPlugin::make()->getTipTapJsExtensions()[0];

        $this->assertStringContainsString('paste-clean.js', $url);
        $this->assertStringContainsString('keep=%2Finternal%2F', $url);

        $this->assertStringContainsString('keep=%2Fx%2F', PasteCleanPlugin::make()->keepLinkPrefixes(['/x/'])->getTipTapJsExtensions()[0]);
    }

    public function test_custom_attributes_plugin_ships_a_php_extension_and_a_parametrised_module(): void
    {
        config()->set('rich-editor-toolkit.attributes', ['data-track']);
        config()->set('rich-editor-toolkit.types', ['myNode', 'bad type']);

        $plugin = CustomAttributesPlugin::make();

        $this->assertInstanceOf(CustomAttributes::class, $plugin->getTipTapPhpExtensions()[0]);

        $url = $plugin->getTipTapJsExtensions()[0];
        $this->assertStringContainsString('custom-attributes.js', $url);
        $this->assertStringContainsString('attr=data-track', $url);
        $this->assertStringContainsString('type=myNode', $url);
        $this->assertStringNotContainsString('bad', $url);
    }

    public function test_the_php_and_js_node_type_lists_agree(): void
    {
        preg_match('/DEFAULT_TYPES = \[(.*?)\]/s', (string) file_get_contents(__DIR__.'/../../../resources/js/src/custom-attributes.js'), $match);
        preg_match_all("/'([A-Za-z]+)'/", $match[1], $names);

        $this->assertSame(CustomAttributes::TYPES, $names[1]);
    }

    public function test_the_php_and_js_owned_classes_styles_and_block_tags_agree(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../../resources/js/src/custom-attributes.js');

        $reflection = new ReflectionClassConstant(CustomDivInline::class, 'BLOCK_TAGS');
        $this->assertSame(1, preg_match('/export const BLOCK_TAGS =\s*(.*?)\n\n/s', $js, $match), 'BLOCK_TAGS');
        preg_match_all('/[-a-z0-9]+/', (string) preg_replace("/'\s*\+\s*'/", ',', $match[1]), $values);
        $this->assertSame($reflection->getValue(), $values[0], 'BLOCK_TAGS');

        // name => tags that own it
        foreach (['OWNED_CLASSES', 'OWNED_STYLES'] as $name) {
            $this->assertSame(1, preg_match('/export const '.$name.' = \{(.*?)\n\}/s', $js, $match), $name);
            preg_match_all("/'?([-a-z]+)'?: \[(.*?)\]/", $match[1], $pairs, PREG_SET_ORDER);

            $owned = [];

            foreach ($pairs as $pair) {
                preg_match_all('/[a-z0-9]+/', $pair[2], $tags);
                $owned[$pair[1]] = $tags[0];
            }

            $this->assertSame((new ReflectionClassConstant(CustomAttributes::class, $name))->getValue(), $owned, $name);
        }
    }

    public function test_embed_plugin_ships_the_iframe_node_and_passes_the_allow_list_to_the_module(): void
    {
        $plugin = EmbedPlugin::make();

        $this->assertInstanceOf(IframeNode::class, $plugin->getTipTapPhpExtensions()[0]);
        $this->assertSame(['embed'], array_map(fn ($tool): string => $tool->getName(), $plugin->getEditorTools()));
        $this->assertStringContainsString('host=player.vimeo.com%2Fvideo%2F', $plugin->getTipTapJsExtensions()[0]);
    }

    public function test_image_url_accepts_only_absolute_http_urls_and_optionally_a_host_list(): void
    {
        $plugin = ImageUrlPlugin::make();

        $this->assertTrue($plugin->accepts('https://a.test/a.png'));
        $this->assertTrue($plugin->accepts('http://a.test/a.png'));
        $this->assertFalse($plugin->accepts('/a.png'));
        $this->assertFalse($plugin->accepts('//a.test/a.png'));
        $this->assertFalse($plugin->accepts('javascript:alert(1)'));
        $this->assertFalse($plugin->accepts('data:image/png;base64,AAAA'));

        $restricted = ImageUrlPlugin::make()->hosts(['CDN.test']);
        $this->assertTrue($restricted->accepts('https://cdn.test/a.png'));
        $this->assertFalse($restricted->accepts('https://other.test/a.png'));
    }

    public function test_the_span_mark_leaves_node_spans_such_as_merge_tags_alone(): void
    {
        $rule = (new CustomSpan)->parseHTML()[0]['getAttrs'];
        $span = static function (string $html): DOMElement {
            $dom = new DOMDocument;
            $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');

            return $dom->getElementsByTagName('span')->item(0);
        };

        $this->assertFalse($rule($span('<span data-type="mergeTag" data-id="name">Name</span>')));
        $this->assertFalse($rule($span('<span data-type="mention" data-id="1">Ann</span>')));
        $this->assertFalse($rule($span('<span class="color">x</span>')));
        $this->assertNull($rule($span('<span class="hook">x</span>')));
    }

    public function test_image_url_tells_a_foreign_host_from_a_malformed_address(): void
    {
        $restricted = ImageUrlPlugin::make()->hosts(['cdn.test']);

        $this->assertNull($restricted->errorFor('https://cdn.test/a.png'));
        $this->assertSame(__('rich-editor-toolkit::rich-editor-toolkit.image_url_host_not_allowed'), $restricted->errorFor('https://other.test/a.png'));
        $this->assertSame(__('rich-editor-toolkit::rich-editor-toolkit.image_url_invalid'), $restricted->errorFor('/a.png'));
        $this->assertSame(__('rich-editor-toolkit::rich-editor-toolkit.image_url_invalid'), ImageUrlPlugin::make()->errorFor('javascript:alert(1)'));
        $this->assertNotSame(
            __('rich-editor-toolkit::rich-editor-toolkit.image_url_invalid'),
            __('rich-editor-toolkit::rich-editor-toolkit.image_url_host_not_allowed'),
        );
    }

    public function test_the_image_url_button_does_not_reuse_the_link_icon(): void
    {
        $this->assertNotSame(Heroicon::Link, ImageUrlPlugin::make()->getEditorTools()[0]->getIcon());
    }

    public function test_the_modules_are_registered_with_filament_assets_and_ship_compiled(): void
    {
        foreach (Assets::MODULES as $module) {
            $this->assertFileExists(__DIR__.'/../../../resources/dist/'.$module.'.js');
            $this->assertStringContainsString('/'.$module.'.js', Assets::url($module));
        }

        $this->assertStringContainsString('?v=', Assets::url('embed'));
        $this->assertStringContainsString('&a=b', Assets::url('embed', ['a' => ['b']]));
    }

    public function test_compiled_modules_are_esm_with_a_default_export_and_no_top_level_window(): void
    {
        foreach (Assets::MODULES as $module) {
            $code = (string) file_get_contents(__DIR__.'/../../../resources/dist/'.$module.'.js');

            $this->assertMatchesRegularExpression('/export\s*\{[^}]*\bas default\b|export default/', $code, $module);

            // Every `window.` must sit inside a function body: brace depth > 0 at that offset.
            $offset = 0;

            while (($position = strpos($code, 'window.', $offset)) !== false) {
                $before = substr($code, 0, $position);
                $this->assertGreaterThan(0, substr_count($before, '{') - substr_count($before, '}'), $module.': top-level window. at '.$position);
                $offset = $position + 1;
            }
        }
    }

    public function test_the_editor_renders_with_every_plugin_and_every_toolbar_button(): void
    {
        config()->set('rich-editor-toolkit.attributes', ['data-track']);

        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $component = Livewire::test(DemoForm::class);
        $html = $component->html();

        $this->assertStringContainsString('data-track', (string) json_encode($component->get('data')));

        foreach (['custom-attributes.js', 'paste-clean.js', 'embed.js', 'fullscreen.js', 'character-count.js', 'source-code.js', 'image-properties.js'] as $module) {
            $this->assertStringContainsString($module, $html);
        }
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.fullscreen'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.source_code'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.clear_formatting'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.embed'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.image_url'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.image_properties'), $html);
    }

    public function test_the_embed_modal_inserts_a_rebuilt_iframe_node_and_rejects_a_foreign_host(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        Livewire::test(DemoForm::class)
            ->callAction(TestAction::make('embed')->schemaComponent('body'), ['url' => 'https://youtu.be/dQw4w9WgXcQ?t=90'])
            ->assertHasNoFormErrors()
            ->assertDispatched('run-rich-editor-commands', function (string $event, array $params): bool {
                $node = $params['commands'][0]['arguments'][0] ?? null;

                return ($node['type'] ?? null) === 'iframe'
                    && ($node['attrs']['src'] ?? null) === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&start=90';
            });

        Livewire::test(DemoForm::class)
            ->callAction(TestAction::make('embed')->schemaComponent('body'), ['url' => 'https://evil.test/x'])
            ->assertNotDispatched('run-rich-editor-commands');
    }

    public function test_the_image_url_modal_inserts_an_image_node_and_rejects_a_relative_path(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        Livewire::test(DemoForm::class)
            ->callAction(TestAction::make('imageUrl')->schemaComponent('body'), ['url' => 'https://cdn.test/a.png', 'alt' => 'A'])
            ->assertDispatched('run-rich-editor-commands', fn (string $event, array $params): bool => ($params['commands'][0]['arguments'][0] ?? null) === ['type' => 'image', 'attrs' => ['src' => 'https://cdn.test/a.png', 'alt' => 'A']]);

        Livewire::test(DemoForm::class)
            ->callAction(TestAction::make('imageUrl')->schemaComponent('body'), ['url' => '/a.png'])
            ->assertNotDispatched('run-rich-editor-commands');
    }

    public function test_paste_clean_is_strict_only_when_asked_or_configured(): void
    {
        $this->assertFalse(PasteCleanPlugin::make()->isStrict());
        $this->assertStringNotContainsString('strict=', PasteCleanPlugin::make()->getTipTapJsExtensions()[0]);

        $this->assertTrue(PasteCleanPlugin::make()->strict()->isStrict());
        $this->assertStringContainsString('strict=1', PasteCleanPlugin::make()->strict()->getTipTapJsExtensions()[0]);
        $this->assertStringNotContainsString('strict=', PasteCleanPlugin::make()->strict(false)->getTipTapJsExtensions()[0]);

        config()->set('rich-editor-toolkit.paste_clean.strict', true);
        $this->assertTrue(PasteCleanPlugin::make()->isStrict());
        $this->assertStringContainsString('strict=1', PasteCleanPlugin::make()->getTipTapJsExtensions()[0]);
        $this->assertFalse(PasteCleanPlugin::make()->strict(false)->isStrict());
    }

    public function test_strict_paste_reads_the_toolbar_so_the_rendered_markup_must_keep_its_shape(): void
    {
        // resources/js/src/paste-strict.js learns what the field offers from the Alpine class
        // binding of every toolbar button: `isActive('<tiptap name>', <options>)`. If Filament
        // changes that markup this fails, instead of strict paste silently turning itself off.
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $html = html_entity_decode(Livewire::test(DemoForm::class)->html(), ENT_QUOTES);

        foreach (['bold', 'link', 'bulletList', 'table'] as $name) {
            $this->assertMatchesRegularExpression("/isActive\(\s*['\"]".$name."['\"]/", $html, $name);
        }

        // A heading's level travels as JSON, with the quotes written as \u0022 by Js::from().
        $this->assertMatchesRegularExpression("/isActive\(\s*['\"]heading['\"].{0,160}?level(?:\\\\u0022|\\\\?['\"])?\s*:\s*2/s", $html);
        $this->assertStringContainsString('fi-fo-rich-editor-toolbar', $html);
        $this->assertStringContainsString('fi-fo-rich-editor', $html);
    }

    public function test_the_fullscreen_plugin_is_a_toggle_tool_without_a_server_half(): void
    {
        $plugin = FullscreenPlugin::make();
        $tool = $plugin->getEditorTools()[0];

        $this->assertSame([], $plugin->getTipTapPhpExtensions());
        $this->assertSame([], $plugin->getEditorActions());
        $this->assertSame('fullscreen', $tool->getName());
        $this->assertTrue($tool->isToggle());
        $this->assertSame(__('rich-editor-toolkit::rich-editor-toolkit.fullscreen'), (string) $tool->getLabel());
        $this->assertStringContainsString('asignuaToggleFullscreen', (string) $tool->getJsHandler());
        $this->assertStringContainsString('storage.asignuaFullscreen', (string) $tool->getActiveJsExpression());
    }

    public function test_the_fullscreen_module_gets_the_stylesheet_and_an_optional_z_index(): void
    {
        $url = FullscreenPlugin::make()->getTipTapJsExtensions()[0];

        $this->assertStringContainsString('fullscreen.js', $url);
        $this->assertStringContainsString('css='.rawurlencode(Assets::styleHref()), $url);
        $this->assertStringContainsString('filament-rich-editor-toolkit-ui', Assets::styleHref());
        $this->assertStringNotContainsString('z=', $url);

        $this->assertStringContainsString('z=50', FullscreenPlugin::make()->zIndex(50)->getTipTapJsExtensions()[0]);

        config()->set('rich-editor-toolkit.fullscreen.z_index', 44);
        $this->assertStringContainsString('z=44', FullscreenPlugin::make()->getTipTapJsExtensions()[0]);
        $this->assertNull(FullscreenPlugin::make()->zIndex(-1)->getZIndex());
    }

    public function test_the_source_code_plugin_is_a_toggle_tool_without_a_server_half(): void
    {
        $plugin = SourceCodePlugin::make();
        $tool = $plugin->getEditorTools()[0];

        $this->assertSame([], $plugin->getTipTapPhpExtensions());
        $this->assertSame([], $plugin->getEditorActions());
        $this->assertSame('sourceCode', $tool->getName());
        $this->assertTrue($tool->isToggle());
        $this->assertSame(__('rich-editor-toolkit::rich-editor-toolkit.source_code'), (string) $tool->getLabel());
        $this->assertStringContainsString('asignuaToggleSourceCode', (string) $tool->getJsHandler());
        $this->assertStringContainsString('storage.asignuaSourceCode', (string) $tool->getActiveJsExpression());
        // The JS locks every tool but this one and fullscreen by matching these handler names.
        $this->assertStringContainsString('asignuaToggleFullscreen', (string) FullscreenPlugin::make()->getEditorTools()[0]->getJsHandler());
    }

    public function test_the_source_code_module_gets_the_stylesheet_the_indent_and_translated_strings(): void
    {
        $url = SourceCodePlugin::make()->getTipTapJsExtensions()[0];

        $this->assertStringContainsString('source-code.js', $url);
        $this->assertStringContainsString('css=', $url);
        $this->assertStringContainsString('indent=2', $url);
        $this->assertStringContainsString('t-label='.rawurlencode(__('rich-editor-toolkit::rich-editor-toolkit.source_code_label')), $url);
        $this->assertStringContainsString('t-notice='.rawurlencode(__('rich-editor-toolkit::rich-editor-toolkit.source_code_unsupported')), $url);

        $this->assertStringContainsString('indent=4', SourceCodePlugin::make()->indent(4)->getTipTapJsExtensions()[0]);

        config()->set('rich-editor-toolkit.source_code.indent', 3);
        $this->assertSame(3, SourceCodePlugin::make()->getIndent());
        $this->assertSame(5, SourceCodePlugin::make()->indent(5)->getIndent());
        $this->assertSame(2, SourceCodePlugin::make()->indent(99)->getIndent());
        $this->assertSame(0, SourceCodePlugin::make()->indent(0)->getIndent());
    }

    public function test_the_ui_stylesheet_covers_the_source_view_in_light_and_dark(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/dist/filament-rich-editor-toolkit-ui.css');

        $this->assertStringContainsString('.asignua-rte-source {', $css);
        $this->assertStringContainsString('.dark .asignua-rte-source {', $css);
        $this->assertStringContainsString('.dark .asignua-rte-source-notice', $css);
        $this->assertStringContainsString('.asignua-rte-fullscreen .asignua-rte-source', $css);
    }

    public function test_the_ui_stylesheet_ships_and_covers_fullscreen_and_the_counter(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../resources/dist/'.Assets::UI_STYLESHEET.'.css');

        $this->assertStringContainsString('.fi-fo-rich-editor.asignua-rte-fullscreen', $css);
        $this->assertStringContainsString('position: fixed', $css);
        $this->assertStringContainsString('--asignua-rte-fullscreen-z, 35', $css);
        $this->assertStringContainsString('html.asignua-rte-fullscreen-lock', $css);
        $this->assertStringContainsString("[data-state='over']", $css);
        $this->assertStringContainsString('.dark .asignua-rte-count', $css);
    }
}
