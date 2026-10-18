<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Domain\StockItemKind;

/**
 * Which products other areas' records lead to: the products a stock item belongs to, those using an attribute
 * set, and every product with commerce details, page by page.
 */
final readonly class ProductLookup
{
    public function __construct(private ProductRepository $products) {}

    /**
     * @return list<int> the product itself, a variation's product, or the products offering an option
     */
    public function idsOfStockItem(StockItemRef $item): array
    {
        return match (StockItemKind::from($item->kind)) {
            StockItemKind::Product => [$item->id],
            StockItemKind::Variation => ($product = $this->products->findByVariation($item->id)) === null ? [] : [$product->id()],
            StockItemKind::Option => $this->products->idsOfferingOption($item->id),
        };
    }

    /**
     * @return list<int>
     */
    public function idsUsingSet(int $setId): array
    {
        return $this->products->idsUsingSet($setId);
    }

    /**
     * @return list<int> in ascending order, after the given id
     */
    public function ids(int $afterId, int $limit): array
    {
        return $this->products->ids($afterId, $limit);
    }
}
