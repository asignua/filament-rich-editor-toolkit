<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The last line for `<iframe src>`: the toolkit's sanitizer drops any `src` that fails
 * {@see EmbedSource::allows()}, whatever produced the markup (a custom block view, a node
 * processor, HTML from outside the schema). An iframe without `src` loads nothing.
 *
 * @internal
 */
final class EmbedSrcSanitizer implements AttributeSanitizerInterface
{
    /**
     * @return list<string>
     */
    public function getSupportedElements(): array
    {
        return ['iframe'];
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return EmbedSource::allows($value) ? $value : null;
    }
}
