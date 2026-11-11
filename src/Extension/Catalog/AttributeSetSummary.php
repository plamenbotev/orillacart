<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** An attribute set: attributes many products offer (API 26). */
final readonly class AttributeSetSummary
{
    /** @param list<AttributeSummary> $attributes */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public array $attributes,
    ) {}
}
