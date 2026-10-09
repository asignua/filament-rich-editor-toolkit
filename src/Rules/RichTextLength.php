<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Rules;

use Asignua\RichEditorToolkit\Support\PlainText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Limits the length of rich text by its PLAIN TEXT, not by its HTML.
 *
 * Filament's `maxLength()` validated the raw HTML string in earlier 5.x releases, so
 * `<p>Hello</p>` was 12 characters and every tag, attribute and entity ate the budget
 * (filamentphp/filament#17028); newer releases count tiptap-php's `getText()`. Either way its
 * number is not the one the editor shows. This rule counts what the reader sees, the way the
 * editor's counter does ({@see PlainText} is the one definition both share), on every Filament
 * version, and adds a word limit:
 *
 *     RichEditor::make('body')->rule(new RichTextLength(max: 500, maxWords: 80))
 *
 * or `->textLength(max: 500)`, which also adds the counter.
 *
 * The state may be an HTML string or the TipTap JSON array of a `->json()` field. Null and an
 * empty string pass, like Laravel's own size rules; so does text with no characters at all: an
 * empty document is `required`'s business, not `min`'s, otherwise an optional field whose
 * editor holds `<p></p>` could never be saved empty.
 */
final class RichTextLength implements ValidationRule
{
    public function __construct(
        public readonly ?int $max = null,
        public readonly ?int $maxWords = null,
        public readonly ?int $min = null,
    ) {
        foreach (['max' => $max, 'maxWords' => $maxWords, 'min' => $min] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException("RichTextLength: [{$name}] cannot be negative.");
            }
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $text = PlainText::from($value);
        $characters = PlainText::characters($text);

        if ($characters === 0) {
            return;
        }

        if ($this->max !== null && $characters > $this->max) {
            $fail('rich-editor-toolkit::rich-editor-toolkit.text_length_max')->translate(['max' => $this->max]);

            return;
        }

        if ($this->min !== null && $characters < $this->min) {
            $fail('rich-editor-toolkit::rich-editor-toolkit.text_length_min')->translate(['min' => $this->min]);

            return;
        }

        if ($this->maxWords !== null && PlainText::words($text) > $this->maxWords) {
            $fail('rich-editor-toolkit::rich-editor-toolkit.text_length_max_words')->translate(['max' => $this->maxWords]);
        }
    }
}
