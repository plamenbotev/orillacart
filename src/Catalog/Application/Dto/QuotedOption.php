<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

final readonly class QuotedOption
{
    public function __construct(
        public int $id,
        public string $attribute,
        public string $name,
        public string $adjustment,
        /** The attribute's and the option's names in the shop's own language, where the shopper's differ (design 51). */
        public ?string $shopAttribute = null,
        public ?string $shopName = null,
    ) {}
}
