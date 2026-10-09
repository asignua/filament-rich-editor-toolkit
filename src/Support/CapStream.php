<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

/**
 * Stream wrapper behind {@see CappedSink}: a `php://temp` that counts the bytes written to it and
 * takes none of the write that would cross the cap (it reports 0 bytes written, which is how a
 * stream says "I will not take this").
 *
 * The method names are PHP's wrapper protocol, not ours.
 *
 * @internal
 */
final class CapStream
{
    public const string PROTOCOL = 'asignua-rte-cap';

    /** @var resource|null set by PHP */
    public $context;

    /** @var resource */
    private $inner;

    private ?CappedSink $sink = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $options = $this->context === null ? [] : (stream_context_get_options($this->context)[self::PROTOCOL] ?? []);
        $sink = $options['sink'] ?? null;
        $inner = fopen('php://temp', 'w+');

        if (!$sink instanceof CappedSink || $inner === false) {
            return false;
        }

        $this->sink = $sink;
        $this->inner = $inner;

        return true;
    }

    public function stream_write(string $data): int
    {
        $sink = $this->sink;
        $length = strlen($data);

        if ($sink === null || $sink->written + $length > $sink->maxBytes) {
            if ($sink !== null) {
                $sink->exceeded = true;
            }

            return 0;
        }

        $written = fwrite($this->inner, $data);

        if ($written === false) {
            return 0;
        }

        $sink->written += $written;

        return $written;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->inner, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return feof($this->inner);
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->inner);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->inner, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->inner);
    }

    public function stream_close(): void
    {
        fclose($this->inner);
    }
}
