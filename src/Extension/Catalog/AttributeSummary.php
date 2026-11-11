<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** An attribute as the shop keeps it, with its options' ids (API 26). */
final readonly class AttributeSummary
{
    /** @param array<int, string> $options option id → name, in order */
    public function __construct(
        public int $id,
        public string $name,
        public bool $required,
        public array $options,
    ) {}
}
