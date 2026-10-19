<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A product made of products: a camera kit of a camera, two batteries and a bag. It has no stock of its own; it
 * is available while every item is, and selling one takes each item's stock. Its price is its own, or the sum
 * of its items with a percentage off.
 *
 * The customer may choose among an item's variations (a black or a brown bag), and add optional items (a
 * tripod). Their picks are the ids of the chosen variations, and of the optional products without variations
 * they add; product and variation ids never collide. A fixed price covers the items every bundle holds: optional
 * items add their own prices.
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
     * @throws CatalogRuleViolation for a bundle without items, with an item twice, only optional items, or a
     *                              discount out of range
     */
    public function __construct(
        public array $items,
        public string $pricing = self::FIXED,
        public ?Money $discount = null,
    ) {
        if ($items === []) {
            throw new CatalogRuleViolation('A bundle holds at least one item.', 'orillacart_invalid_bundle');
        }
        // Each pick names one item: a variation is in one item, and a product without variations is one item.
        $picks = array_merge(...array_map(static fn(BundleItem $item): array => $item->pickIds(), $items));
        if (\count(array_unique($picks)) !== \count($picks)) {
            throw new CatalogRuleViolation('A bundle lists each item once, with its quantity.', 'orillacart_invalid_bundle');
        }
        if (array_filter($items, static fn(BundleItem $item): bool => !$item->optional) === []) {
            throw new CatalogRuleViolation('A bundle holds at least one item that is not optional.', 'orillacart_invalid_bundle');
        }
        if (!\in_array($pricing, [self::FIXED, self::SUM], true)) {
            throw new CatalogRuleViolation('A bundle has its own price, or the sum of its items.', 'orillacart_invalid_bundle');
        }
        if ($discount !== null && !$discount->isPercentOff()) {
            throw new CatalogRuleViolation('A bundle discount is a percentage from 0 to 100.', 'orillacart_invalid_bundle');
        }
    }

    /** Whether the customer has choices to make or optional items to add. */
    public function asksTheCustomer(): bool
    {
        return array_filter($this->items, static fn(BundleItem $item): bool => $item->asksTheCustomer()) !== [];
    }

    /**
     * The items one bundle holds with the customer's picks, each with its variation; without picks, the first
     * variation of each choice and no optional items.
     *
     * @param list<int>|null $picks
     *
     * @return list<array{BundleItem, ?int}>
     *
     * @throws InvalidSelection for a choice not made, two variations of one item, or a pick the bundle does not offer
     */
    public function select(?array $picks): array
    {
        $left = $picks === null ? [] : array_values(array_unique($picks));
        $selected = [];
        foreach ($this->items as $item) {
            $chosen = array_values(array_intersect($item->pickIds(), $left));
            $left = array_values(array_diff($left, $chosen));
            if (\count($chosen) > 1) {
                throw new InvalidSelection('Choose one variation of each bundle item.');
            }
            if ($picks === null || !$item->asksTheCustomer()) {
                // A fixed item, or what is shown before the customer chose.
                if (!$item->optional) {
                    $selected[] = [$item, $item->variationIds[0] ?? null];
                }
                continue;
            }
            if ($chosen === []) {
                if (!$item->optional) {
                    throw new InvalidSelection('Choose a variation of each bundle item.');
                }
                continue;
            }
            $selected[] = [$item, $item->variationIds === [] ? null : $chosen[0]];
        }
        if ($left !== []) {
            throw new InvalidSelection('The bundle does not offer that choice.');
        }

        return $selected;
    }

    /** Its price from its items' prices, when it is priced as their sum. */
    public function priceOf(Money $itemsTotal): Money
    {
        return $this->discount === null ? $itemsTotal : $itemsTotal->subtract($itemsTotal->percent((string) $this->discount))->atLeastZero();
    }

    /**
     * @return array{items: list<array{product_id: int, variation_ids: list<int>, quantity: int, optional: bool}>, pricing: string, discount: ?string}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn(BundleItem $item): array => ['product_id' => $item->productId, 'variation_ids' => $item->variationIds, 'quantity' => $item->quantity, 'optional' => $item->optional], $this->items),
            'pricing' => $this->pricing,
            'discount' => $this->discount?->toFixed(),
        ];
    }

    /**
     * @param array{items: list<array{product_id: int, variation_ids: list<int>, quantity: int, optional: bool}>, pricing: string, discount: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(static fn(array $item): BundleItem => new BundleItem($item['product_id'], $item['variation_ids'], $item['quantity'], $item['optional']), $data['items']),
            $data['pricing'],
            $data['discount'] === null ? null : Money::of($data['discount']),
        );
    }
}
