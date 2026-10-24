<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Events;

use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Catalog\Domain\Variation;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A product or variation saved as "count stock" that no stockroom holds yet starts at 0, not without limit: a new
 * variation, a copied product, one whose stock was never entered. A bundle's stock is its items'.
 */
#[AsEventListener]
final readonly class StartCountingStock
{
    public function __construct(
        private ProductRepository $products,
        private StockService $stock,
    ) {}

    public function __invoke(ProductChanged $event): void
    {
        $product = $this->products->find($event->productId);
        if ($product === null || $product->isBundle()) {
            return;
        }
        $refs = $product->hasVariations()
            ? array_values(array_map(static fn(Variation $variation): StockItemRef => StockItemRef::variation($variation->id()), array_filter($product->variations(), static fn(Variation $variation): bool => $variation->stockMode() === StockMode::Tracked)))
            : ($product->details()->stockMode === StockMode::Tracked ? [StockItemRef::product($product->id())] : []);
        $this->stock->countFromNothing($refs);
    }
}
