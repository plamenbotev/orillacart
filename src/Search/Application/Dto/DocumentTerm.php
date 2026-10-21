<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * A category, brand or tag a product is filed under.
 */
final readonly class DocumentTerm
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
