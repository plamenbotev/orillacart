<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * An attribute option a product or variant offers, by the names shoppers see: "Size", "M".
 */
final readonly class DocumentOption
{
    public function __construct(
        public string $attribute,
        public string $name,
    ) {}
}
