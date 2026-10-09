<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Plugins\LinkOptionsPlugin;
use Asignua\RichEditorToolkit\RichEditorToolkit;
use Asignua\RichEditorToolkit\Support\LinkAttributes;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Livewire\DemoForm;

class LinkOptionsTest extends TestCase
{
    private function render(string $html): string
    {
        return RichEditorToolkit::renderer($html)->toHtml();
    }

    /**
     * The attributes of the first <a> of a fragment, as the browser would read them.
     *
     * @return array<string, string>
     */
    private function anchor(string $html, int $index = 0): array
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
        $element = $document->getElementsByTagName('a')->item($index);

        $this->assertInstanceOf(\DOMElement::class, $element, 'no <a> at '.$index.' in '.$html);

        $attributes = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        return $attributes;
    }

    // --- what Filament does on its own ---------------------------------------------------------

    public function test_stock_filament_passes_target_and_rel_through_untouched_and_unchecked(): void
    {
        // The reason for the plugin: nothing limits what a stored rel / target may say.
        $html = RichContentRenderer::make('<p><a href="https://x.test/" target="_top" rel="whatever opener">x</a></p>')->toHtml();

        $this->assertSame('_top', $this->anchor($html)['target']);
        $this->assertSame('whatever opener', $this->anchor($html)['rel']);
    }

    // --- rendering -------------------------------------------------------------------------------

    public function test_rel_and_target_survive_the_toolkit_renderer(): void
    {
        $html = $this->render('<p><a href="https://x.test/" target="_blank" rel="nofollow sponsored ugc noopener noreferrer">x</a></p>');

        $this->assertSame('_blank', $this->anchor($html)['target']);
        $this->assertSame('nofollow sponsored ugc noopener noreferrer', $this->anchor($html)['rel']);
        $this->assertSame('https://x.test/', $this->anchor($html)['href']);
    }

    public function test_a_new_tab_always_gets_noopener_noreferrer_even_when_the_stored_html_has_no_rel(): void
    {
        $html = $this->render('<p><a href="https://x.test/" target="_blank">x</a></p>');

        $this->assertSame('noopener noreferrer', $this->anchor($html)['rel']);
    }

    public function test_the_forced_tokens_are_added_to_the_editors_own_without_duplicates(): void
    {
        $html = $this->render('<p><a href="https://x.test/" target="_blank" rel="nofollow noopener">x</a></p>');

        $this->assertSame('nofollow noopener noreferrer', $this->anchor($html)['rel']);
    }

    #[DataProvider('foreignTargets')]
    public function test_target_is_either_blank_or_gone(string $target): void
    {
        $html = $this->render('<p><a href="https://x.test/" target="'.$target.'" rel="nofollow">x</a></p>');
        $attributes = $this->anchor($html);

        $this->assertArrayNotHasKey('target', $attributes);
        $this->assertSame('nofollow', $attributes['rel'], 'no new tab, so nothing is forced');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignTargets(): array
    {
        return ['self' => ['_self'], 'top' => ['_top'], 'parent' => ['_parent'], 'a frame' => ['myframe'], 'empty' => ['']];
    }

    public function test_rel_tokens_outside_the_whitelist_are_dropped_and_case_is_normalised(): void
    {
        $html = $this->render('<p><a href="https://x.test/" rel="NoFollow opener javascript:alert(1) bogus UGC">x</a></p>');

        $this->assertSame('nofollow ugc', $this->anchor($html)['rel']);
    }

    public function test_a_rel_that_ends_up_empty_is_removed_altogether(): void
    {
        $html = $this->render('<p><a href="https://x.test/" rel="opener bogus">x</a></p>');

        $this->assertArrayNotHasKey('rel', $this->anchor($html));
    }

    public function test_the_whitelist_is_configurable(): void
    {
        config()->set('rich-editor-toolkit.link_options.rel_tokens', ['nofollow', 'noopener', 'noreferrer']);

        $html = $this->render('<p><a href="https://x.test/" target="_blank" rel="nofollow ugc me">x</a></p>');

        $this->assertSame('nofollow noopener noreferrer', $this->anchor($html)['rel']);
    }

    public function test_the_default_whitelist_keeps_rel_me_and_author_but_not_opener(): void
    {
        $html = $this->render('<p><a href="https://m.test/@a" rel="me author opener">x</a></p>');

        $this->assertSame('me author', $this->anchor($html)['rel']);
    }

    public function test_other_attributes_of_the_link_are_untouched(): void
    {
        $html = $this->render('<p><a class="btn" id="go" href="/page?a=1&amp;b=2" target="_blank" rel="nofollow">x</a></p>');
        $attributes = $this->anchor($html);

        $this->assertSame('btn', $attributes['class']);
        $this->assertSame('go', $attributes['id']);
        $this->assertSame('/page?a=1&b=2', $attributes['href']);
        $this->assertSame('nofollow noopener noreferrer', $attributes['rel']);
    }

    public function test_an_injection_attempt_in_rel_or_target_cannot_break_out_of_the_tag(): void
    {
        $html = $this->render('<p><a href="https://x.test/" target="_blank&quot; onclick=&quot;evil()" rel="nofollow&quot; onmouseover=&quot;evil()">x</a></p>');

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertArrayNotHasKey('rel', $this->anchor($html), 'every token of it is malformed');
        $this->assertArrayNotHasKey('target', $this->anchor($html));
    }

    public function test_several_links_in_one_text_are_treated_each_on_its_own(): void
    {
        $html = $this->render('<p><a href="https://a.test/" target="_blank">a</a> and <a href="/b" rel="ugc">b</a> and <a href="https://c.test/">c</a></p>');

        $this->assertSame('noopener noreferrer', $this->anchor($html, 0)['rel']);
        $this->assertSame('ugc', $this->anchor($html, 1)['rel']);
        $this->assertArrayNotHasKey('rel', $this->anchor($html, 2));
        $this->assertArrayNotHasKey('target', $this->anchor($html, 2));
    }

    public function test_a_page_without_links_is_returned_as_is(): void
    {
        $this->assertSame('<p>No links here</p>', $this->render('<p>No links here</p>'));
    }

    public function test_without_the_plugin_the_renderer_does_not_normalise(): void
    {
        $renderer = RichContentRenderer::make('<p><a href="https://x.test/" target="_top" rel="whatever">x</a></p>');

        $this->assertSame('_top', $this->anchor(RichEditorToolkit::toHtml($renderer))['target']);
    }

    public function test_a_stricter_sanitizer_config_still_lets_rel_and_target_through_when_the_plugin_is_attached(): void
    {
        $this->app->scoped(\Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig::class, fn () => (new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('a', ['href'])
            ->allowRelativeLinks());

        $html = $this->render('<p><a href="https://x.test/" target="_blank" rel="nofollow">x</a></p>');

        $this->assertSame('_blank', $this->anchor($html)['target']);
        $this->assertSame('nofollow noopener noreferrer', $this->anchor($html)['rel']);
    }

    // --- external links ----------------------------------------------------------------------------

    public function test_external_rel_is_added_to_links_that_leave_the_site_only(): void
    {
        config()->set('rich-editor-toolkit.link_options.external_rel', ['nofollow']);

        $html = $this->render('<p><a href="https://other.test/x">o</a> <a href="https://site.test/x">s</a> <a href="/x">r</a> <a href="//cdn.test/x">p</a> <a href="mailto:a@b.test">m</a> <a href="#top">h</a></p>');

        $this->assertSame('nofollow', $this->anchor($html, 0)['rel']);
        $this->assertArrayNotHasKey('rel', $this->anchor($html, 1), 'app.url is https://site.test');
        $this->assertArrayNotHasKey('rel', $this->anchor($html, 2));
        $this->assertSame('nofollow', $this->anchor($html, 3)['rel'], 'protocol-relative leaves the site too');
        $this->assertArrayNotHasKey('rel', $this->anchor($html, 4));
        $this->assertArrayNotHasKey('rel', $this->anchor($html, 5));
    }

    public function test_external_rel_merges_with_the_editors_rel_and_a_new_tab(): void
    {
        config()->set('rich-editor-toolkit.link_options.external_rel', ['nofollow']);

        $html = $this->render('<p><a href="https://other.test/" target="_blank" rel="ugc">x</a></p>');

        $this->assertSame('ugc nofollow noopener noreferrer', $this->anchor($html)['rel']);
    }

    public function test_www_and_internal_hosts_count_as_the_site(): void
    {
        config()->set('rich-editor-toolkit.link_options.external_rel', ['nofollow']);
        config()->set('rich-editor-toolkit.link_options.internal_hosts', ['shop.site.test']);

        $html = $this->render('<p><a href="https://www.site.test/x">w</a> <a href="https://SHOP.site.test/x">s</a> <a href="https://blog.site.test/x">b</a></p>');

        $this->assertArrayNotHasKey('rel', $this->anchor($html, 0));
        $this->assertArrayNotHasKey('rel', $this->anchor($html, 1));
        $this->assertSame('nofollow', $this->anchor($html, 2)['rel'], 'a subdomain is another host unless listed');
    }

    public function test_external_rel_is_applied_at_render_time_only_and_only_whitelisted_tokens_count(): void
    {
        config()->set('rich-editor-toolkit.link_options.external_rel', ['nofollow', 'bogus']);

        $stored = '<p><a href="https://other.test/">x</a></p>';
        $html = RichEditorToolkit::toHtml(RichContentRenderer::make($stored)->plugins(RichEditorToolkit::plugins()));

        $this->assertSame('nofollow', $this->anchor($html)['rel']);
        $this->assertStringNotContainsString('rel=', $stored);
        $this->assertSame(['nofollow', 'bogus'], LinkOptionsPlugin::make()->getExternalRel());
        $this->assertSame(['ugc'], LinkOptionsPlugin::make()->externalRel(['ugc'])->getExternalRel());
    }

    public function test_the_internal_host_defaults_include_the_app_url(): void
    {
        $this->assertSame(['site.test'], LinkOptionsPlugin::make()->getInternalHosts());
        $this->assertSame(['a.test', 'site.test'], LinkOptionsPlugin::make()->internalHosts(['a.test'])->getInternalHosts());
    }

    // --- compatibility ---------------------------------------------------------------------------

    public function test_a_rich_links_placeholder_href_is_not_external_and_keeps_its_options(): void
    {
        config()->set('rich-editor-toolkit.link_options.external_rel', ['nofollow']);

        $html = $this->render('<p><a href="/ee-link/page/12" target="_blank" rel="ugc">x</a></p>');

        $this->assertSame('/ee-link/page/12', $this->anchor($html)['href']);
        $this->assertSame('ugc noopener noreferrer', $this->anchor($html)['rel']);
    }

    // --- LinkAttributes -------------------------------------------------------------------------

    public function test_is_external_edge_cases(): void
    {
        $internal = ['site.test'];

        $this->assertTrue(LinkAttributes::isExternal('HTTPS://Other.test/x', $internal));
        $this->assertTrue(LinkAttributes::isExternal('http://other.test', $internal));
        $this->assertFalse(LinkAttributes::isExternal('https://site.test/x', $internal));
        $this->assertFalse(LinkAttributes::isExternal('/x', $internal));
        $this->assertFalse(LinkAttributes::isExternal('x/y', $internal));
        $this->assertFalse(LinkAttributes::isExternal('tel:+3800', $internal));
        $this->assertFalse(LinkAttributes::isExternal('https:///nohost', $internal));
        $this->assertTrue(LinkAttributes::isExternal('https://site.test.evil.test/', $internal), 'a suffix is not the site');
    }

    // --- the dialog ---------------------------------------------------------------------------------

    public function test_the_tool_is_enabled_only_inside_a_link_and_reads_its_attributes(): void
    {
        $tool = LinkOptionsPlugin::make()->getEditorTools()[0];

        $this->assertSame('linkOptions', $tool->getName());
        $this->assertSame('link', $tool->getActiveKey());
        $this->assertTrue($tool->isDisabledWhenNotActive());
        $this->assertSame([], LinkOptionsPlugin::make()->getTipTapPhpExtensions());
        $this->assertSame([], LinkOptionsPlugin::make()->getTipTapJsExtensions());
    }

    public function test_the_attributes_for_a_new_tab_and_chosen_tokens(): void
    {
        $this->assertSame(
            ['target' => '_blank', 'rel' => 'nofollow ugc noopener noreferrer'],
            LinkOptionsPlugin::attributesFrom(['newTab' => true, 'rel' => ['ugc', 'nofollow']]),
        );
        $this->assertSame(['target' => null, 'rel' => 'sponsored'], LinkOptionsPlugin::attributesFrom(['newTab' => false, 'rel' => ['sponsored']]));
        $this->assertSame(['target' => null, 'rel' => null], LinkOptionsPlugin::attributesFrom(['newTab' => false, 'rel' => []]));
        $this->assertSame(['target' => null, 'rel' => null], LinkOptionsPlugin::attributesFrom([]));
    }

    public function test_unmanaged_tokens_survive_an_edit_and_managed_ones_follow_the_dialog(): void
    {
        $this->assertSame(
            ['target' => null, 'rel' => 'me ugc'],
            LinkOptionsPlugin::attributesFrom(['newTab' => false, 'rel' => ['ugc']], 'me nofollow noopener noreferrer'),
            'noopener noreferrer go with the new tab, nofollow was unticked',
        );
    }

    public function test_the_dialog_cannot_be_talked_into_writing_an_unlisted_token(): void
    {
        $this->assertSame(['target' => null, 'rel' => 'nofollow'], LinkOptionsPlugin::attributesFrom(['newTab' => false, 'rel' => ['nofollow', 'opener', 'bogus']]));
    }

    public function test_the_existing_rel_sent_by_the_browser_is_filtered_through_the_whitelist(): void
    {
        $this->assertSame(
            ['target' => null, 'rel' => 'me author'],
            LinkOptionsPlugin::attributesFrom(['newTab' => false, 'rel' => []], 'ME opener bogus javascript:x author me'),
            'unlisted tokens and opener are not stored, the rest is lowercased and deduplicated',
        );

        config()->set('rich-editor-toolkit.link_options.rel_tokens', ['me', 'opener']);

        $this->assertSame(
            ['target' => '_blank', 'rel' => 'me noopener noreferrer'],
            LinkOptionsPlugin::attributesFrom(['newTab' => true, 'rel' => []], 'me opener author'),
            'opener is never stored, even when an integrator listed it',
        );
    }

    public function test_editable_tokens_for_the_checkboxes(): void
    {
        $this->assertSame(['nofollow', 'ugc'], LinkOptionsPlugin::editableTokens('UGC me nofollow noopener'));
        $this->assertSame([], LinkOptionsPlugin::editableTokens(null));
    }

    public function test_the_modal_prefills_and_updates_the_link_mark_not_the_text(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $arguments = ['editorSelection' => ['type' => 'text', 'anchor' => 3, 'head' => 3], 'newTab' => true, 'rel' => 'nofollow noopener noreferrer'];

        Livewire::test(DemoForm::class)
            ->mountAction(TestAction::make('linkOptions')->schemaComponent('body')->arguments($arguments))
            ->assertSchemaStateSet(['newTab' => true, 'rel' => ['nofollow']]);

        Livewire::test(DemoForm::class)
            ->callAction(TestAction::make('linkOptions')->schemaComponent('body')->arguments($arguments), ['newTab' => true, 'rel' => ['sponsored']])
            ->assertHasNoFormErrors()
            ->assertDispatched('run-rich-editor-commands', function (string $event, array $params): bool {
                $commands = $params['commands'] ?? [];

                return ($commands[0]['name'] ?? null) === 'extendMarkRange'
                    && ($commands[0]['arguments'] ?? null) === ['link']
                    && ($commands[1]['name'] ?? null) === 'updateAttributes'
                    && ($commands[1]['arguments'] ?? null) === ['link', ['target' => '_blank', 'rel' => 'sponsored noopener noreferrer']];
            });
    }

    public function test_a_real_selection_updates_the_selected_range_without_extending_it(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        Livewire::test(DemoForm::class)
            ->callAction(
                TestAction::make('linkOptions')->schemaComponent('body')->arguments(['editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 6]]),
                ['newTab' => false, 'rel' => []],
            )
            ->assertDispatched('run-rich-editor-commands', fn (string $event, array $params): bool => count($params['commands']) === 1
                && $params['commands'][0]['arguments'] === ['link', ['target' => null, 'rel' => null]]);
    }

    public function test_the_editor_renders_the_tool_and_the_renderer_helper_lists_the_plugin(): void
    {
        $this->actingAs(\Workbench\App\Models\User::factory()->create());

        $this->assertStringContainsString(__('rich-editor-toolkit::rich-editor-toolkit.link_options'), Livewire::test(DemoForm::class)->html());
        $this->assertContains(LinkOptionsPlugin::class, array_map(get_class(...), RichEditorToolkit::plugins()));
    }
}
