<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Tests\Unit;

use Asignua\RichEditorToolkit\Support\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the shared definition. tests/js/character-count.test.mjs runs the same
 * fixtures against the editor document: the validation rule and the counter must agree.
 */
class PlainTextTest extends TestCase
{
    /**
     * @return array<string, array{string, array{name: string, html?: string, json?: array<mixed>, characters: int, words: int}}>
     */
    public static function cases(): array
    {
        /** @var array{cases: list<array{name: string, html?: string, json?: array<mixed>, characters: int, words: int}>} $fixtures */
        $fixtures = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/text-length.json'), true, flags: JSON_THROW_ON_ERROR);

        $cases = [];

        foreach ($fixtures['cases'] as $case) {
            $cases[$case['name']] = [$case['name'], $case];
        }

        return $cases;
    }

    /**
     * @param array{name: string, html?: string, json?: array<mixed>, characters: int, words: int} $case
     */
    #[DataProvider('cases')]
    public function test_the_shared_fixtures(string $name, array $case): void
    {
        foreach (['html', 'json'] as $format) {
            if (!isset($case[$format])) {
                continue;
            }

            $text = PlainText::from($case[$format]);

            $this->assertSame($case['characters'], PlainText::characters($text), "{$name} ({$format}): characters");
            $this->assertSame($case['words'], PlainText::words($text), "{$name} ({$format}): words");
        }
    }

    public function test_html_and_json_of_the_same_document_give_the_same_text(): void
    {
        foreach (self::cases() as [, $case]) {
            if (isset($case['html'], $case['json'])) {
                $this->assertSame(PlainText::from($case['json']), PlainText::from($case['html']), $case['name']);
            }
        }
    }

    public function test_blocks_are_joined_with_a_newline_and_empty_ones_dropped(): void
    {
        $this->assertSame(['a', 'b'], PlainText::blocks('<p>a</p><p></p><p>b</p>'));
        $this->assertSame("a\nb", PlainText::from('<p>a</p><p></p><p>b</p>'));
    }

    public function test_a_tag_soup_is_not_a_character_budget(): void
    {
        // Filament's maxLength() would see 85 characters here.
        $html = '<p class="a" style="color: red;"><strong>Hi</strong></p>';

        $this->assertGreaterThan(50, strlen($html));
        $this->assertSame(2, PlainText::characters(PlainText::from($html)));
    }

    public function test_script_and_style_are_not_text(): void
    {
        $this->assertSame('ok', PlainText::from('<p>ok</p><script>alert(1)</script><style>p{}</style>'));
    }

    public function test_unusable_state_has_no_text(): void
    {
        foreach ([null, '', '   ', [], 5, new \stdClass] as $state) {
            $this->assertSame('', PlainText::from($state));
        }
    }

    public function test_words_use_the_explicit_whitespace_list(): void
    {
        $this->assertSame(2, PlainText::words("a\u{00A0}b"));
        $this->assertSame(2, PlainText::words("a\u{3000}b"));
        $this->assertSame(2, PlainText::words("a\u{FEFF}b"));
        $this->assertSame(2, PlainText::words("a\nb"));
        $this->assertSame(0, PlainText::words("  \n "));
    }

    public function test_characters_count_code_points_and_ignore_only_newlines(): void
    {
        $this->assertSame(2, PlainText::characters('😀😀'));
        $this->assertSame(3, PlainText::characters('a b'));
        $this->assertSame(2, PlainText::characters("a\nb"));
    }
}
