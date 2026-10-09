<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Feature;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Plugins\CharacterCountPlugin;
use Asignua\RichEditorToolkit\Rules\RichTextLength;
use Asignua\RichEditorToolkit\Tests\TestCase;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Livewire\Livewire;
use Workbench\App\Livewire\DemoForm;
use Workbench\App\Models\User;

class TextLengthTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private function errors(mixed $value, RichTextLength $rule): array
    {
        return Validator::make(['body' => $value], ['body' => [$rule]])->errors()->toArray();
    }

    /**
     * An editor needs a schema container before it can list its plugins.
     */
    private function bound(RichEditor $editor): RichEditor
    {
        Schema::make(new DemoForm)->components([$editor])->getComponents();

        return $editor;
    }

    public function test_it_counts_plain_text_not_html(): void
    {
        $html = '<p class="lead" style="color:red"><strong>Hello</strong> world</p>';

        $this->assertGreaterThan(11, strlen($html));
        $this->assertSame([], $this->errors($html, new RichTextLength(max: 11)));
        $this->assertArrayHasKey('body', $this->errors($html, new RichTextLength(max: 10)));
    }

    public function test_the_max_message_names_the_attribute_and_the_limit(): void
    {
        $errors = $this->errors('<p>Hello world</p>', new RichTextLength(max: 5));

        $this->assertSame([__('rich-editor-toolkit::rich-editor-toolkit.text_length_max', ['max' => 5, 'attribute' => 'body'])], $errors['body']);
        $this->assertStringContainsString('body', $errors['body'][0]);
        $this->assertStringContainsString('5', $errors['body'][0]);
    }

    public function test_min_and_max_words(): void
    {
        $this->assertArrayHasKey('body', $this->errors('<p>abc</p>', new RichTextLength(min: 5)));
        $this->assertSame([], $this->errors('<p>abcde</p>', new RichTextLength(min: 5)));

        $errors = $this->errors('<p>one two three</p>', new RichTextLength(maxWords: 2));
        $this->assertStringContainsString('2', $errors['body'][0]);
        $this->assertSame([], $this->errors('<p>one two</p>', new RichTextLength(maxWords: 2)));
    }

    public function test_it_accepts_tiptap_json_as_well_as_html(): void
    {
        $json = [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]]],
        ];

        $this->assertSame([], $this->errors($json, new RichTextLength(max: 11)));
        $this->assertArrayHasKey('body', $this->errors($json, new RichTextLength(max: 10)));
    }

    public function test_empty_content_passes_even_with_a_minimum(): void
    {
        foreach ([null, '', '<p></p>', ['type' => 'doc', 'content' => [['type' => 'paragraph']]]] as $empty) {
            $this->assertSame([], $this->errors($empty, new RichTextLength(min: 5, max: 10)));
        }
    }

    public function test_the_message_is_translated(): void
    {
        app()->setLocale('uk');

        $errors = $this->errors('<p>Hello world</p>', new RichTextLength(max: 5));

        $this->assertStringContainsString('символів', $errors['body'][0]);
    }

    public function test_a_negative_limit_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RichTextLength(max: -1);
    }

    public function test_the_macro_adds_the_rule_and_the_counter_and_keeps_other_plugins(): void
    {
        $editor = $this->bound(RichEditor::make('body')
            ->plugins([CharacterCountPlugin::make()->showWords()])
            ->textLength(max: 500, maxWords: 80));

        $plugins = $editor->getPlugins();

        // plugins() appends: the one set earlier survives, the macro's comes after it.
        $this->assertCount(2, $plugins);
        $this->assertContainsOnlyInstancesOf(CharacterCountPlugin::class, $plugins);

        $urls = $editor->getTipTapJsExtensions();
        $this->assertStringContainsString('max=500', $urls[1]);
        $this->assertStringContainsString('max-words=80', $urls[1]);
        $this->assertStringContainsString('words=1', $urls[1]);

        $rules = array_filter($editor->getValidationRules(), fn (mixed $rule): bool => $rule instanceof RichTextLength);
        $this->assertCount(1, $rules);
    }

    public function test_the_macro_can_skip_the_counter(): void
    {
        $editor = $this->bound(RichEditor::make('body')->textLength(max: 10, counter: false));

        $this->assertSame([], $editor->getPlugins());
    }

    public function test_the_macro_shows_words_only_when_asked_or_limited(): void
    {
        $this->assertStringNotContainsString('words=1', $this->bound(RichEditor::make('a')->textLength(max: 10))->getTipTapJsExtensions()[0]);
        $this->assertStringContainsString('words=1', $this->bound(RichEditor::make('b')->textLength(max: 10, showWords: true))->getTipTapJsExtensions()[0]);
        $this->assertStringContainsString('words=1', $this->bound(RichEditor::make('c')->textLength(maxWords: 5))->getTipTapJsExtensions()[0]);
    }

    public function test_the_counter_module_gets_limits_labels_and_the_stylesheet(): void
    {
        $url = CharacterCountPlugin::make()->maxCharacters(500)->getTipTapJsExtensions()[0];

        $this->assertStringContainsString('character-count.js', $url);
        $this->assertStringContainsString('max=500', $url);
        $this->assertStringNotContainsString('max-words', $url);
        $this->assertStringContainsString('css='.rawurlencode(Assets::styleHref()), $url);
        $this->assertStringContainsString('t-chars-max='.rawurlencode(__('rich-editor-toolkit::rich-editor-toolkit.characters_count_max', ['count' => ':count', 'max' => ':max'])), $url);
        $this->assertStringContainsString('t-over-chars=', $url);
        $this->assertStringContainsString('warn=0.9', $url);
    }

    public function test_the_warning_share_comes_from_config_and_is_validated(): void
    {
        config()->set('rich-editor-toolkit.character_count.warn_at', 0.75);
        $this->assertSame(0.75, CharacterCountPlugin::make()->getWarnAt());
        $this->assertSame(0.5, CharacterCountPlugin::make()->warnAt(0.5)->getWarnAt());

        config()->set('rich-editor-toolkit.character_count.warn_at', 7);
        $this->assertSame(0.9, CharacterCountPlugin::make()->getWarnAt());
    }

    public function test_the_counter_plugin_has_no_server_half_and_no_tool(): void
    {
        $plugin = CharacterCountPlugin::make();

        $this->assertSame([], $plugin->getTipTapPhpExtensions());
        $this->assertSame([], $plugin->getEditorTools());
        $this->assertSame([], $plugin->getEditorActions());
    }

    public function test_the_rule_is_enforced_through_the_form(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DemoForm::class)
            ->set('data.body', '<p>'.str_repeat('x', 501).'</p>')
            ->call('submit')
            ->assertHasErrors(['data.body']);

        Livewire::test(DemoForm::class)
            ->set('data.body', '<p>'.str_repeat('x', 500).'</p>')
            ->call('submit')
            ->assertHasNoErrors();
    }
}
