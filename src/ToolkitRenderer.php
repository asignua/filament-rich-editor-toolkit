<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Filament\Forms\Components\RichEditor\RichContentRenderer;

/**
 * A `RichContentRenderer` whose `toHtml()` uses the toolkit's own sanitizer
 * ({@see RichEditorToolkit::toHtml()}) instead of Filament's shared one, so the allowances
 * (`<iframe>`, extra attribute names) apply to THIS renderer and nowhere else in the app.
 */
class ToolkitRenderer extends RichContentRenderer
{
    public function toHtml(): string
    {
        return RichEditorToolkit::toHtml($this);
    }
}
