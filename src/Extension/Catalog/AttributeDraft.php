<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * An attribute with its options, by name, for a product or an attribute set (API 26). Saved over one named alike,
 * it keeps its id and the ids of the options named alike, so variations and orders that name them stay right.
 */
final readonly class AttributeDraft
{
    /**
     * @param list<string> $options in order
     * @param bool         $required the shopper must choose one
     */
    public function __construct(
        public string $name,
        public array $options,
        public bool $required = false,
    ) {}
}
