<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\BundleItem;

final readonly class BundleView
{
    /**
     * @param list<array{product_id: int, variation_ids: list<int>, quantity: int, optional: bool, name: string, variations: list<array{id: int, name: string}>}> $items
     */
    public function __construct(
        public array $items,
        public string $pricing,
        public ?string $discount,
    ) {}

    /**
     * @param \Closure(int, ?int): string $name a product's name, or its variation's options
     */
    public static function fromBundle(Bundle $bundle, \Closure $name): self
    {
        return new self(
            array_map(static fn(BundleItem $item): array => [
                'product_id' => $item->productId,
                'variation_ids' => $item->variationIds,
                'quantity' => $item->quantity,
                'optional' => $item->optional,
                'name' => $name($item->productId, null),
                'variations' => array_map(static fn(int $id): array => ['id' => $id, 'name' => $name($item->productId, $id)], $item->variationIds),
            ], $bundle->items),
            $bundle->pricing,
            $bundle->discount === null ? null : (string) $bundle->discount,
        );
    }
}
