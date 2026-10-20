<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\LowStockView;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Inventory\Application\Dto\ItemLevelView;
use OrillaCart\Inventory\Application\Dto\StockroomView;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\SettingsService;

/**
 * The items an active stockroom holds few of: as many as the threshold or fewer. The threshold is the item's own
 * (a variation's, else its product's), else the stockroom's, else the shop's; attribute options have the
 * stockroom's or the shop's. Only counted stock: items always available or not for sale are left out.
 */
final readonly class LowStock
{
    public function __construct(
        private ProductRepository $products,
        private ProductContent $content,
        private StockService $stock,
        private StockroomService $stockrooms,
        private SettingsService $settings,
    ) {}

    /** @return list<LowStockView> by stockroom, then name */
    public function items(): array
    {
        $shop = $this->settings->current()->lowStockThreshold;
        $rooms = [];
        foreach ($this->stockrooms->stockrooms() as $room) {
            if ($room->active) {
                $rooms[$room->id] = $room;
            }
        }
        $most = max($shop, $this->products->highestLowStockThreshold() ?? 0, ...array_map(static fn(StockroomView $room): int => $room->lowStockThreshold ?? 0, array_values($rooms)));
        $levels = $this->stock->atMost($most);
        $options = [];
        foreach ($this->products->optionsWithIds(array_values(array_map(static fn(ItemLevelView $level): int => $level->item->id, array_filter($levels, static fn(ItemLevelView $level): bool => $level->item->kind === 'option')))) as $option) {
            $options[$option->id()] = $option;
        }

        $low = [];
        foreach ($levels as $level) {
            $room = $rooms[$level->stockroomId] ?? null;
            $item = $room === null ? null : $this->item($level->item->kind, $level->item->id, $options);
            if ($room === null || $item === null) {
                continue;
            }
            $threshold = $item['threshold'] ?? $room->lowStockThreshold ?? $shop;
            if ($level->quantity <= $threshold) {
                $low[] = new LowStockView($level->item->kind, $level->item->id, $item['name'], $item['sku'], $room->id, $room->name, $level->quantity, $threshold);
            }
        }
        usort($low, static fn(LowStockView $a, LowStockView $b): int => [$a->stockroom, $a->name] <=> [$b->stockroom, $b->name]);

        return $low;
    }

    /**
     * The item's name, SKU and own threshold; null for one gone, or whose stock is not counted.
     *
     * @param array<int, AttributeOption> $options
     *
     * @return array{name: string, sku: ?string, threshold: ?int}|null
     */
    private function item(string $kind, int $id, array $options): ?array
    {
        if ($kind === 'option') {
            $option = $options[$id] ?? null;

            return $option === null ? null : ['name' => $option->attribute()->name() . ': ' . $option->name(), 'sku' => null, 'threshold' => null];
        }
        if ($kind === 'variation') {
            $product = $this->products->findByVariation($id);
            $variation = $product?->variation($id);
            if ($product === null || $variation === null || $variation->stockMode() !== StockMode::Tracked) {
                return null;
            }
            $label = implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options()));

            return ['name' => $this->content->name($product->id()) . ' – ' . $label, 'sku' => $variation->sku(), 'threshold' => $variation->lowStockThreshold() ?? $product->details()->lowStockThreshold];
        }
        $product = $this->products->find($id);
        if ($product === null || $product->hasVariations() || $product->details()->stockMode !== StockMode::Tracked) {
            return null;
        }

        return ['name' => $this->content->name($id), 'sku' => $product->details()->sku, 'threshold' => $product->details()->lowStockThreshold];
    }
}
