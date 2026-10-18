<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A shipment's label, for the admin screen to save or print. The REST API speaks JSON, so the file is base64.
 */
final readonly class LabelView
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content,
    ) {}
}
