<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * A product inside a bundle, and how many of it. For a product with variations it lists the variations the bundle
 * offers: one is the bundle's, several are the customer's choice. An optional item is left out unless the
 * customer adds it.
 */
final readonly class BundleItem
{
    /** @var list<int> */
    public array $variationIds;

    /**
     * @param list<int> $variationIds empty for a product without variations
     *
     * @throws CatalogRuleViolation for fewer than one, or a variation listed twice
     */
    public function __construct(
        public int $productId,
        array $variationIds,
        public int $quantity,
        public bool $optional = false,
    ) {
        if ($quantity < 1 || $quantity > 9999) {
            throw new CatalogRuleViolation('A bundle holds between 1 and 9999 of each item.', 'orillacart_invalid_bundle');
        }
        if (\count(array_unique($variationIds)) !== \count($variationIds)) {
            throw new CatalogRuleViolation('A bundle item lists each variation once.', 'orillacart_invalid_bundle');
        }
        $this->variationIds = array_values($variationIds);
    }

    /** Whether the customer chooses which variation the bundle holds. */
    public function isChoice(): bool
    {
        return \count($this->variationIds) > 1;
    }

    /** Whether the customer has something to decide about it: to add it, or which variation. */
    public function asksTheCustomer(): bool
    {
        return $this->optional || $this->isChoice();
    }

    /**
     * The ids that pick it: its variations, or the product's own id when it has none.
     *
     * @return list<int>
     */
    public function pickIds(): array
    {
        return $this->variationIds === [] ? [$this->productId] : $this->variationIds;
    }
}
