<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Inventory\Application\Dto\StockItemRef;

/**
 * What the stock screens call an item: a product's name, a variation's with its options ("Hoodie – S"), or an
 * attribute option's with its attribute ("Size: S"); with its SKU, its product, its own low stock threshold, and
 * whether its stock is counted.
 */
final readonly class StockItemNames
{
    public function __construct(
        private ProductRepository $products,
        private ProductContent $content,
    ) {}

    /**
     * @param list<StockItemRef> $items
     *
     * @return array<string, array{name: string, sku: ?string, productId: ?int, threshold: ?int, counted: bool}> by item ("variation:12"); one gone is left out
     */
    public function of(array $items): array
    {
        $options = [];
        foreach ($this->products->optionsWithIds(array_values(array_map(static fn(StockItemRef $item): int => $item->id, array_filter($items, static fn(StockItemRef $item): bool => $item->kind === 'option')))) as $option) {
            $options[$option->id()] = $option;
        }
        $named = [];
        foreach ($items as $item) {
            $key = "{$item->kind}:{$item->id}";
            $name = $this->one($item, $options);
            if ($name !== null) {
                $named[$key] = $name;
            }
        }

        return $named;
    }

    /**
     * @param array<int, AttributeOption> $options
     *
     * @return array{name: string, sku: ?string, productId: ?int, threshold: ?int, counted: bool}|null
     */
    private function one(StockItemRef $item, array $options): ?array
    {
        if ($item->kind === 'option') {
            $option = $options[$item->id] ?? null;

            return $option === null ? null : ['name' => $option->attribute()->name() . ': ' . $option->name(), 'sku' => null, 'productId' => $option->attribute()->product()?->id(), 'threshold' => null, 'counted' => true];
        }
        if ($item->kind === 'variation') {
            $product = $this->products->findByVariation($item->id);
            if ($product === null) {
                return null;
            }
            $variation = $product->variation($item->id);
            $label = implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options()));

            return ['name' => $this->content->name($product->id()) . ' – ' . $label, 'sku' => $variation->sku(), 'productId' => $product->id(), 'threshold' => $variation->lowStockThreshold() ?? $product->details()->lowStockThreshold, 'counted' => $variation->stockMode() === StockMode::Tracked];
        }
        $product = $this->products->find($item->id);
        if ($product === null) {
            return null;
        }

        return ['name' => $this->content->name($item->id), 'sku' => $product->details()->sku, 'productId' => $item->id, 'threshold' => $product->details()->lowStockThreshold, 'counted' => !$product->hasVariations() && $product->details()->stockMode === StockMode::Tracked];
    }
}
