<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A product made of products: a camera kit of a camera, two batteries and a bag. It has no stock of its own; it
 * is available while every item is, and selling one takes each item's stock. Its price is its own, or the sum
 * of its items with a percentage off.
 */
final readonly class Bundle
{
    /** The bundle's own price, as the product sets it. */
    public const string FIXED = 'fixed';
    /** The items' prices added up, with the discount off. */
    public const string SUM = 'sum';

    /**
     * @param list<BundleItem> $items
     *
     * @throws CatalogRuleViolation for a bundle without items, with an item twice, or a discount out of range
     */
    public function __construct(
        public array $items,
        public string $pricing = self::FIXED,
        public ?Money $discount = null,
    ) {
        if ($items === []) {
            throw new CatalogRuleViolation('A bundle holds at least one item.', 'orillacart_invalid_bundle');
        }
        $keys = array_map(static fn(BundleItem $item): string => $item->productId . ':' . ($item->variationId ?? 0), $items);
        if (\count(array_unique($keys)) !== \count($keys)) {
            throw new CatalogRuleViolation('A bundle lists each item once, with its quantity.', 'orillacart_invalid_bundle');
        }
        if (!\in_array($pricing, [self::FIXED, self::SUM], true)) {
            throw new CatalogRuleViolation('A bundle has its own price, or the sum of its items.', 'orillacart_invalid_bundle');
        }
        if ($discount !== null && ($discount->isNegative() || $discount->compare(Money::of('100')) > 0)) {
            throw new CatalogRuleViolation('A bundle discount is a percentage from 0 to 100.', 'orillacart_invalid_bundle');
        }
    }

    /** Its price from its items' prices, when it is priced as their sum. */
    public function priceOf(Money $itemsTotal): Money
    {
        return $this->discount === null ? $itemsTotal : $itemsTotal->subtract($itemsTotal->percent((string) $this->discount))->atLeastZero();
    }

    /**
     * @return array{items: list<array{product_id: int, variation_id: ?int, quantity: int}>, pricing: string, discount: ?string}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn(BundleItem $item): array => ['product_id' => $item->productId, 'variation_id' => $item->variationId, 'quantity' => $item->quantity], $this->items),
            'pricing' => $this->pricing,
            'discount' => $this->discount?->toFixed(),
        ];
    }

    /**
     * @param array{items: list<array{product_id: int, variation_id: ?int, quantity: int}>, pricing: string, discount: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(static fn(array $item): BundleItem => new BundleItem($item['product_id'], $item['variation_id'], $item['quantity']), $data['items']),
            $data['pricing'],
            $data['discount'] === null ? null : Money::of($data['discount']),
        );
    }
}
