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

    public function test_a_field_can_opt_out_and_the_stylesheet_respects_it(): void
    {
        $editor = \Filament\Forms\Components\RichEditor::make('body')->withoutStickyToolbar();

        $this->assertSame('off', $editor->getExtraAttributes()['data-toolkit-sticky'] ?? null);
        $this->assertArrayNotHasKey('data-toolkit-sticky', \Filament\Forms\Components\RichEditor::make('x')->withoutStickyToolbar(false)->getExtraAttributes());

        $css = (string) file_get_contents(__DIR__.'/../../../resources/dist/filament-rich-editor-toolkit.css');
        $this->assertStringContainsString("[data-toolkit-sticky='off']", $css);
    }
}
