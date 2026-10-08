<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\StickyToolbarPlugin;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

class StickyToolbarTest extends TestCase
{
    public function test_the_panel_links_the_stylesheet_and_sets_the_offset(): void
    {
        Filament::getCurrentPanel()?->boot();

        $html = (string) FilamentView::renderHook(PanelsRenderHook::STYLES_AFTER);

        $this->assertStringContainsString('filament-rich-editor-toolkit', $html);
        $this->assertStringContainsString('--asignua-rte-sticky-offset: 5rem;', $html);
        $this->assertStringContainsString('--asignua-rte-sticky-z: 5;', $html);
    }

    public function test_the_offset_is_only_ever_a_css_length(): void
    {
        $plugin = StickyToolbarPlugin::make();

        $plugin->offset('calc(4rem + 8px)');
        $this->assertSame('calc(4rem + 8px)', $plugin->getOffset());

        $plugin->offset('0; } body { display:none');
        $this->assertNull($plugin->getOffset());

        $plugin->offset('</style><script>alert(1)</script>');
        $this->assertNull($plugin->getOffset());

        $plugin->offset(null);
        config()->set('rich-editor-toolkit.sticky_toolbar.offset', '3rem');
        $this->assertSame('3rem', $plugin->getOffset());
    }

    public function test_the_stylesheet_makes_the_toolbar_sticky_and_unblocks_clipping_ancestors(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../resources/dist/filament-rich-editor-toolkit.css');

        $this->assertStringContainsString('position: sticky', $css);
        $this->assertStringContainsString('overflow: clip', $css);
        $this->assertStringContainsString('--asignua-rte-sticky-offset', $css);
    }

    public function test_the_plugin_offset_comes_before_filaments_variable_which_wins_only_in_modals_and_on_fields_with_their_own(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../resources/dist/filament-rich-editor-toolkit.css');

        // Filament defines its variable on EVERY editor, so it can only be a fallback.
        $this->assertMatchesRegularExpression(
            '/top:\s*var\(\s*--asignua-rte-sticky-offset,\s*var\(--fi-fo-rich-editor-sticky-offset/',
            $css,
        );
        $this->assertStringContainsString(".fi-fo-rich-editor[style*='--fi-fo-rich-editor-sticky-offset']", $css);
        $this->assertMatchesRegularExpression('/\.fi-modal,[^{]*\{\s*--asignua-rte-sticky-offset:\s*initial/', $css);
    }

    public function test_a_field_can_opt_out_and_the_stylesheet_respects_it(): void
    {
        $editor = \Filament\Forms\Components\RichEditor::make('body')->withoutStickyToolbar();

        $this->assertSame('off', $editor->getExtraAttributes()['data-toolkit-sticky'] ?? null);
        $this->assertArrayNotHasKey('data-toolkit-sticky', \Filament\Forms\Components\RichEditor::make('x')->withoutStickyToolbar(false)->getExtraAttributes());

        $css = (string) file_get_contents(__DIR__.'/../../../resources/dist/filament-rich-editor-toolkit.css');
        $this->assertStringContainsString("[data-toolkit-sticky='off']", $css);
        // A container whose editors all opted out keeps its own overflow.
        $this->assertStringContainsString(":has(.fi-fo-rich-editor-toolbar:not([data-toolkit-sticky='off'] *))", $css);
    }

    public function test_the_toolbar_background_follows_the_panel_theme(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../resources/dist/filament-rich-editor-toolkit.css');

        $this->assertStringContainsString('var(--color-white', $css);
        $this->assertStringContainsString('var(--gray-900', $css);
    }
}
