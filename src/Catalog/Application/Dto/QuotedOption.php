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
    ) {}
}
