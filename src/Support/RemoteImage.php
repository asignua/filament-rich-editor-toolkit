<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

/**
 * A downloaded picture: bytes whose type was decided by their magic bytes
 * ({@see RemoteImageFetcher::sniffMime()}).
 */
final readonly class RemoteImage
{
    public function __construct(
        public string $mime,
        public string $bytes,
    ) {}

    public function toDataUrl(): string
    {
        return 'data:'.$this->mime.';base64,'.base64_encode($this->bytes);
    }
}
