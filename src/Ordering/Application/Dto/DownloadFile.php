<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * A bought file ready to send: where it is on disk, and the name and type the browser gets.
 */
final readonly class DownloadFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
    ) {}
}
