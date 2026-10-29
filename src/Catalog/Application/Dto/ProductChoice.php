<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

/** A product as a picker offers it: its id, the product's own, and its name in the admin's language. */
final readonly class ProductChoice
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
