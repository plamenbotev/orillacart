<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\BundleItem;

final readonly class BundleView
{
    /**
     * @param list<array{product_id: int, variation_id: ?int, quantity: int, name: string}> $items
     */
    public function __construct(
        public array $items,
        public string $pricing,
        public ?string $discount,
    ) {}

    /**
     * @param \Closure(BundleItem): string $name
     */
    public static function fromBundle(Bundle $bundle, \Closure $name): self
    {
        return new self(
            array_map(static fn(BundleItem $item): array => ['product_id' => $item->productId, 'variation_id' => $item->variationId, 'quantity' => $item->quantity, 'name' => $name($item)], $bundle->items),
            $bundle->pricing,
            $bundle->discount === null ? null : (string) $bundle->discount,
        );
    }
}
