<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use RuntimeException;

/**
 * The place a download is written to: an in-memory stream that REFUSES the write that would take
 * it past the cap. A failed write makes the HTTP client (libcurl) abort the transfer on the spot,
 * which is what a limit has to do: an exception thrown in a progress callback does not stop curl.
 *
 * A plain `resource` (not a PSR-7 stream) on purpose: it is what Laravel's `Http::fake()` and both
 * Guzzle majors accept as a `sink`. The cap is a write FILTER on that resource ({@see CapFilter}).
 *
 * @internal
 */
final class CappedSink
{
    /** Set once a write was refused. */
    public bool $exceeded = false;

    public int $written = 0;

    /** @var resource */
    public $stream;

    public function __construct(public readonly int $maxBytes)
    {
        if (!in_array(CapFilter::NAME, stream_get_filters(), true)) {
            stream_filter_register(CapFilter::NAME, CapFilter::class);
        }

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }

        stream_filter_append($stream, CapFilter::NAME, STREAM_FILTER_WRITE, $this);

        $this->stream = $stream;
    }
}
