<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

/**
 * What an import row changes of a product's post; null keeps it.
 */
final readonly class ProductPostChange
{
    /**
     * @param list<string>|null $categories each as its path: "Lighting > Desk lamps"
     * @param list<string>|null $brands
     * @param list<string>|null $tags
     */
    public function __construct(
        public ?string $name = null,
        public ?string $status = null,
        public ?string $description = null,
        public ?string $shortDescription = null,
        public ?array $categories = null,
        public ?array $brands = null,
        public ?array $tags = null,
    ) {}

    public function isEmpty(): bool
    {
        return array_filter([$this->name, $this->status, $this->description, $this->shortDescription, $this->categories, $this->brands, $this->tags], static fn(mixed $value): bool => $value !== null) === [];
    }
}
