<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\LowStockView;
use OrillaCart\Catalog\Application\Dto\ProductFilter;
use OrillaCart\Catalog\Domain\ProductCriteria;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The products the shop's admin list narrows to. A product's stock is its variations' when it has them, else its
 * own. A bundle has none of its own: it is in stock while each required item is (one variation of a choice, as
 * many as the bundle holds), "some out" when a choice or optional item is not, out of stock when it cannot sell.
 */
final readonly class ProductFinder
{
    public const string IN_STOCK = 'in_stock';
    public const string LOW_STOCK = 'low_stock';
    public const string SOME_OUT = 'some_out';
    public const string OUT_OF_STOCK = 'out_of_stock';
    public const string OWED = 'owed';
    public const string ALWAYS_AVAILABLE = 'always_available';
    public const string NOT_FOR_SALE = 'not_for_sale';
    /** The most products one add-on's search gives. */
    public const int MAX_FOUND = 500;
    public const array STOCK = [self::IN_STOCK, self::LOW_STOCK, self::SOME_OUT, self::OUT_OF_STOCK, self::OWED, self::ALWAYS_AVAILABLE, self::NOT_FOR_SALE];

    public function __construct(
        private ProductRepository $products,
        private StockService $stock,
        private LowStock $lowStock,
        private SettingsService $settings,
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
    ) {}

    /**
     * The add-ons' filters, for the list to offer.
     *
     * @return array<string, string> labels by id
     */
    public function addOnFilters(): array
    {
        $filters = [];
        // Their products are those marked with their ids (MarkedProduct).
        foreach ($this->extensions->productFilters() as $filter) {
            $filters[$filter->id()] = $filter->label();
        }

        return $filters;
    }

    /**
     * The products a search finds besides by name: by SKU, the product's or a variation's, and by what add-ons
     * keep about them.
     *
     * @return list<int>
     */
    public function matching(string $text): array
    {
        $ids = array_fill_keys($this->products->idsWithSku($text), true);
        foreach ($this->extensions->productSearches() as $search) {
            foreach (\array_slice($this->asked(\sprintf('The product search %s', $search->id()), static fn(): mixed => $search->findProducts($text)), 0, self::MAX_FOUND) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param ProductFilter $filter checked as its constraints say, as the list's request is
     *
     * @return list<int>|null the products' ids in ascending order; null when the filter narrows nothing
     */
    public function ids(ProductFilter $filter): ?array
    {
        $criteria = new ProductCriteria(
            $filter->kind,
            $filter->shown,
            $filter->minPrice === null ? null : Money::of($filter->minPrice),
            $filter->maxPrice === null ? null : Money::of($filter->maxPrice),
            $filter->shippingClass,
            $filter->attributeSet,
            $filter->missing,
            $filter->shown === ProductCriteria::PRICE_HIDDEN && $this->settings->current()->hidePrices,
            addOnMark: $filter->addOn,
        );
        $ids = $criteria->narrows() ? $this->products->idsMatching($criteria) : null;
        $stock = $filter->stock ?? ($filter->stockroom === null ? null : self::IN_STOCK);
        if ($stock !== null) {
            $stocked = $this->stocked($stock, $filter->stockroom);
            $ids = $ids === null ? $stocked : array_values(array_intersect($ids, $stocked));
        }

        return $ids;
    }

    /** @return list<int> */
    private function stocked(string $state, ?int $stockroom): array
    {
        $totals = $this->stock->totals($stockroom);
        $marked = match ($state) {
            self::OWED => self::keys($this->stock->itemsOwed($stockroom)),
            self::LOW_STOCK => array_fill_keys(array_map(
                static fn(LowStockView $low): string => "{$low->kind}:{$low->id}",
                array_filter($this->lowStock->items(), static fn(LowStockView $low): bool => $stockroom === null || $low->stockroomId === $stockroom),
            ), true),
            default => [],
        };
        // Stockroom stock is only counted stock: an item always available is in none. One no stockroom holds is not
        // limited, as the shop sells it, but is in no stockroom either.
        $counted = $stockroom === null ? $this->stock->counted() : [];
        $sells = static fn(StockMode $mode, string $key, int $quantity): bool => match ($mode) {
            StockMode::AlwaysAvailable => $stockroom === null,
            StockMode::Tracked => ($stockroom === null && !isset($counted[$key])) || ($totals[$key] ?? 0) >= $quantity,
            StockMode::Unavailable => false,
        };

        $products = [];
        $modes = [];
        foreach ($this->products->stockedItems() as $item) {
            $key = $item['variation'] === null ? 'product:' . $item['product'] : 'variation:' . $item['variation'];
            $products[$item['product']][$key] = $item['mode'];
            $modes[$key] = $item['mode'];
        }

        $ids = [];
        foreach ($products as $id => $items) {
            $counted = array_filter($items, static fn(StockMode $mode): bool => $mode === StockMode::Tracked);
            $held = array_filter($counted, static fn(StockMode $mode, string $key): bool => $sells($mode, $key, 1), ARRAY_FILTER_USE_BOTH);
            $always = \in_array(StockMode::AlwaysAvailable, $items, true);
            $matches = match ($state) {
                self::IN_STOCK => $held !== [] || ($always && $stockroom === null),
                self::SOME_OUT => $held !== [] && \count($held) < \count($counted),
                self::OUT_OF_STOCK => $counted !== [] && $held === [] && !$always,
                self::LOW_STOCK, self::OWED => array_intersect_key($counted, $marked) !== [],
                self::ALWAYS_AVAILABLE => $always,
                default => array_filter($items, static fn(StockMode $mode): bool => $mode !== StockMode::Unavailable) === [],
            };
            if ($matches) {
                $ids[] = $id;
            }
        }

        // A bundle sells while each of its required items does, as many as it holds: one of a choice's variations.
        foreach ($this->products->bundles() as $bundle) {
            if ($bundle['mode'] === StockMode::Unavailable) {
                if ($state === self::NOT_FOR_SALE) {
                    $ids[] = $bundle['product'];
                }
                continue;
            }
            $sellable = true;
            $short = false;
            $picks = [];
            foreach ($bundle['bundle']->items as $item) {
                $keys = $item->variationIds === [] ? ['product:' . $item->productId] : array_map(static fn(int $id): string => 'variation:' . $id, $item->variationIds);
                $selling = array_filter($keys, static fn(string $key): bool => isset($modes[$key]) && $sells($modes[$key], $key, $item->quantity));
                $short = $short || \count($selling) < \count($keys);
                $sellable = $sellable && ($item->optional || $selling !== []);
                foreach ($keys as $key) {
                    $picks[$key] = $modes[$key] ?? StockMode::Unavailable;
                }
            }
            $matches = match ($state) {
                self::IN_STOCK => $sellable,
                self::SOME_OUT => $sellable && $short,
                self::OUT_OF_STOCK => !$sellable,
                self::LOW_STOCK, self::OWED => array_intersect_key($picks, $marked) !== [],
                self::ALWAYS_AVAILABLE => $picks !== [] && array_filter($picks, static fn(StockMode $mode): bool => $mode !== StockMode::AlwaysAvailable) === [],
                default => false,
            };
            if ($matches) {
                $ids[] = $bundle['product'];
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * What an add-on's search answered, when it is product ids; one that fails or answers otherwise finds nothing,
     * and the list still shows.
     *
     * @param \Closure(): mixed $ask
     *
     * @return list<int>
     */
    private function asked(string $what, \Closure $ask): array
    {
        try {
            $answer = $ask();
        } catch (\Throwable $e) {
            $this->logger->error('{what} failed: {message}', ['what' => $what, 'message' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
        $ids = [];
        foreach (\is_array($answer) ? $answer : [null] as $id) {
            if (!\is_int($id) || $id < 1) {
                $this->logger->error('{what} answered something other than a list of product ids; it finds nothing.', ['what' => $what]);

                return [];
            }
            $ids[$id] = $id;
        }
        ksort($ids);

        return array_values($ids);
    }

    /**
     * @param list<StockItemRef> $items
     *
     * @return array<string, true>
     */
    private static function keys(array $items): array
    {
        return array_fill_keys(array_map(static fn(StockItemRef $item): string => "{$item->kind}:{$item->id}", $items), true);
    }
}
