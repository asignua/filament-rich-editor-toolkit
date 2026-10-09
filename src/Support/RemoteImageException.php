<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use RuntimeException;
use Throwable;

/**
 * Why {@see RemoteImageFetcher} refused a URL. `$reason` is one of its `ERR_*` constants; it is
 * the only thing that is ever sent to the browser (never the exception message, which can name
 * an internal address).
 */
final class RemoteImageException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Remote image refused: '.$reason, 0, $previous);
    }
}
