<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

/**
 * An invoice or credit note as a PDF to attach: INV-2026-00042.pdf.
 */
final readonly class InvoiceFile
{
    public function __construct(
        public string $filename,
        public string $content,
    ) {}
}
