<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** A product category (API 26). */
final readonly class CategorySummary
{
    /** @param string $path its name after its parents', "Lighting > Desk lamps" */
    public function __construct(
        public int $id,
        public string $name,
        public int $parentId,
        public string $path,
        public int $products,
    ) {}
}
