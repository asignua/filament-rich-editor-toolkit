<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use RuntimeException;

/**
 * The place a download is written to: an in-memory stream that REFUSES the write that would take
 * it past the cap. The refusal is a SHORT write (`fwrite()` returns 0), never an error and never an
 * exception: the HTTP client hands libcurl the number of bytes the sink took, libcurl sees that it
 * is not the number it delivered and aborts the transfer on the spot. That is what a limit has to
 * do (an exception thrown in a progress callback does not stop curl), and it is the one contract
 * that behaves the same on both Guzzle majors: Guzzle 7's write callback is `return $sink->write()`
 * (an exception thrown there is NOT caught and curl keeps downloading until the timeout), Guzzle 8
 * catches it and returns 0 itself.
 *
 * A plain `resource` (not a PSR-7 stream) on purpose: it is what Laravel's `Http::fake()` and both
 * Guzzle majors accept as a `sink`. The cap lives in a userland stream wrapper ({@see CapStream}).
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
        if (!in_array(CapStream::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(CapStream::PROTOCOL, CapStream::class);
        }

        $stream = fopen(CapStream::PROTOCOL.'://sink', 'w+', false, stream_context_create([CapStream::PROTOCOL => ['sink' => $this]]));

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }

        $this->stream = $stream;
    }
}
