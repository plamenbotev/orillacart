<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

/**
 * Command: keep a file a shopper chose for a product's file field. It is at a temporary path until kept.
 */
final readonly class SendUpload
{
    public function __construct(
        public int $productId,
        public string $fieldId,
        public string $temporaryPath,
        public string $filename,
        /** The sender's address, for their own limit. */
        public string $address = '',
    ) {}
}
