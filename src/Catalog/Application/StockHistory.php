<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\StockHistoryQuery;
use OrillaCart\Catalog\Application\Dto\StockHistoryView;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Inventory\Application\Dto\MovementView;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\MovementSearch;
use OrillaCart\Inventory\Domain\StockItem;
use OrillaCart\Inventory\Domain\StockItemKind;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Shared\Users\UserNames;

/**
 * The stock history: every move of a level, named as the stock screens name items, with who made it, and what
 * moved in and out in all, by stockroom and reason, for the time looked at.
 */
final readonly class StockHistory
{
    public function __construct(
        private StockService $stock,
        private StockItemNames $names,
        private ProductRepository $products,
        private UserNames $users,
    ) {}

    public function history(StockHistoryQuery $query): StockHistoryView
    {
        $search = new MovementSearch(
            $query->product === null ? [] : $this->itemsOf($query->product),
            $query->stockroom,
            $query->reason === null ? null : StockReason::from($query->reason),
            $query->order,
            $query->from === null ? null : new \DateTimeImmutable($query->from),
            $query->before === null ? null : new \DateTimeImmutable($query->before),
        );
        [$movements, $total] = $this->stock->movements($search, ($query->page - 1) * $query->perPage, $query->perPage);
        $names = $this->names->of(array_map(static fn(MovementView $movement) => $movement->item, $movements));
        $users = $this->users->names(array_values(array_filter(array_map(static fn(MovementView $movement): ?int => $movement->userId, $movements), \is_int(...))));

        return new StockHistoryView(
            array_map(static function (MovementView $movement) use ($names, $users): array {
                $name = $names["{$movement->item->kind}:{$movement->item->id}"] ?? null;

                return [
                    'id' => $movement->id,
                    'kind' => $movement->item->kind,
                    'item_id' => $movement->item->id,
                    'product_id' => $name['productId'] ?? null,
                    'name' => $name['name'] ?? "{$movement->item->kind} #{$movement->item->id}",
                    'sku' => $name['sku'] ?? null,
                    'stockroom_id' => $movement->stockroomId,
                    'stockroom' => $movement->stockroom,
                    'delta' => $movement->delta,
                    'after' => $movement->after,
                    'reason' => $movement->reason,
                    'order_id' => $movement->orderId,
                    'user' => $movement->userId === null ? null : $users[$movement->userId] ?? "#{$movement->userId}",
                    'at' => $movement->at,
                ];
            }, $movements),
            $this->stock->movedTotals($search),
            $total,
            $query->page,
            max(1, (int) ceil($total / $query->perPage)),
        );
    }

    /** @return list<StockItem> the product and its variations */
    private function itemsOf(int $productId): array
    {
        $items = [new StockItem(StockItemKind::Product, $productId)];
        foreach ($this->products->find($productId)?->variations() ?? [] as $variation) {
            $items[] = new StockItem(StockItemKind::Variation, $variation->id());
        }

        return $items;
    }
}
