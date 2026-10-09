<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use php_user_filter;

/**
 * Write filter of {@see CappedSink}: counts the bytes and fails the write that crosses the cap.
 *
 * @internal
 */
final class CapFilter extends php_user_filter
{
    public const string NAME = 'asignua-rte.cap';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $sink = $this->params;

        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($sink instanceof CappedSink) {
                if ($sink->written + $bucket->datalen > $sink->maxBytes) {
                    $sink->exceeded = true;

                    return PSFS_ERR_FATAL;
                }

                $sink->written += $bucket->datalen;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
