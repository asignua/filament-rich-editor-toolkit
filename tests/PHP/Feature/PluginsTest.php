<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Extensions\CustomAttributes;
use Asignua\RichEditorToolkit\Extensions\CustomDivInline;
use Asignua\RichEditorToolkit\Extensions\IframeNode;
use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Plugins\ImageUrlPlugin;
use Asignua\RichEditorToolkit\Plugins\PasteCleanPlugin;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Livewire\Livewire;
use ReflectionClassConstant;
use Workbench\App\Livewire\DemoForm;

class PluginsTest extends TestCase
{
    public function test_every_plugin_implements_the_filament_contract(): void
    {
        foreach ([PasteCleanPlugin::class, CustomAttributesPlugin::class, EmbedPlugin::class, ImageUrlPlugin::class] as $class) {
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

        foreach (['OWNED_CLASSES' => [CustomAttributes::class, 'OWNED_CLASSES'], 'OWNED_STYLES' => [CustomAttributes::class, 'OWNED_STYLES'], 'BLOCK_TAGS' => [CustomDivInline::class, 'BLOCK_TAGS']] as $name => [$class, $constant]) {
            $this->assertSame(1, preg_match('/export const '.$name.' =\s*(.*?)(?:\]|\n\n)/s', $js, $match), $name);
            preg_match_all('/[-a-z0-9]+/', (string) preg_replace("/'\s*\+\s*'/", ',', $match[1]), $values);

            $reflection = new ReflectionClassConstant($class, $constant);
            $this->assertSame($reflection->getValue(), $values[0], $name);
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

        foreach (['custom-attributes.js', 'paste-clean.js', 'embed.js'] as $module) {
            $this->assertStringContainsString($module, $html);
        }
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.clear_formatting'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.embed'), $html);
        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.image_url'), $html);
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
}
