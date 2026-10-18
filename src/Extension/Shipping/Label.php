<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * A shipment's label as the courier gives it: a PDF, usually.
 */
final readonly class Label
{
    public function __construct(
        public string $content,
        public string $mimeType = 'application/pdf',
    ) {}
}
