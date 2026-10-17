<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain\Event;

use OrillaCart\Shared\Domain\Event\PublicEvent;

/**
 * Products, variations or attribute options were deleted; whatever other areas keep about them can go.
 * Announced as the action orillacart_catalog_items_removed with the three lists of ids.
 */
final readonly class CatalogItemsRemoved implements PublicEvent
{
    /**
     * @param list<int> $productIds
     * @param list<int> $variationIds
     * @param list<int> $optionIds
     */
    public function __construct(
        public array $productIds = [],
        public array $variationIds = [],
        public array $optionIds = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->productIds === [] && $this->variationIds === [] && $this->optionIds === [];
    }

    public function hookName(): string
    {
        return 'catalog_items_removed';
    }

    /**
     * @return list<list<int>>
     */
    public function hookArguments(): array
    {
        return [$this->productIds, $this->variationIds, $this->optionIds];
    }
}
