<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * A file an email carries, made for it: an invoice as a PDF.
 */
final readonly class EmailAttachment
{
    public function __construct(
        public string $filename,
        public string $content,
    ) {}
}
