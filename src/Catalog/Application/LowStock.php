<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\LowStockView;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Inventory\Application\Dto\ItemLevelView;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
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
        private StockItemNames $names,
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
        $names = $this->names->of(array_map(static fn(ItemLevelView $level): StockItemRef => $level->item, $levels));

        $low = [];
        foreach ($levels as $level) {
            $room = $rooms[$level->stockroomId] ?? null;
            $item = $names["{$level->item->kind}:{$level->item->id}"] ?? null;
            if ($room === null || $item === null || !$item['counted']) {
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
}
