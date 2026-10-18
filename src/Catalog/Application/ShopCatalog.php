<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\DownloadFile;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Catalog\Domain\Variation;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * The catalog as the storefront shows it: today's prices, sales, stock and the choices each product offers.
 */
final readonly class ShopCatalog
{
    public function __construct(
        private ProductRepository $products,
        private ProductContent $content,
        private StockService $stock,
        private ClockInterface $clock,
        private SettingsService $settings,
    ) {}

    /**
     * @throws ProductNotFound also for a product post whose commerce details were never saved
     */
    public function offer(int $id): ProductOffer
    {
        $product = $this->products->find($id) ?? throw new ProductNotFound($id);

        return $this->toOffer($product, $this->clock->now());
    }

    /**
     * The offers of the products that have commerce details, keyed by id, in the order asked for.
     *
     * @param list<int> $ids
     *
     * @return array<int, ProductOffer>
     */
    public function offers(array $ids): array
    {
        $now = $this->clock->now();
        $found = [];
        foreach ($this->products->findMany($ids) as $product) {
            $found[$product->id()] = $product;
        }
        // The stock of every product and variation that counts it, in one query rather than one each.
        $refs = [];
        foreach ($found as $product) {
            if ($product->details()->stockMode === StockMode::Tracked) {
                $refs[] = StockItemRef::product($product->id());
            }
            foreach ($product->variations() as $variation) {
                if ($variation->stockMode() === StockMode::Tracked) {
                    $refs[] = StockItemRef::variation($variation->id());
                }
            }
        }
        $stock = $refs === [] ? [] : $this->stock->availableEach($refs);
        $offers = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $offers[$id] = $this->toOffer($found[$id], $now, $stock);
            }
        }

        return $offers;
    }

    /**
     * When today's price of the product or one of its variations next changes, as a sale starts or ends; null
     * when no change is scheduled or the product has no commerce details.
     */
    public function nextPriceChange(int $id): ?\DateTimeImmutable
    {
        $product = $this->products->find($id);
        if ($product === null) {
            return null;
        }
        $now = $this->clock->now();
        $next = null;
        $pricings = [$product->details()->pricing, ...array_map(static fn(Variation $variation): Pricing => $variation->pricing(), $product->variations())];
        foreach ($pricings as $pricing) {
            $sale = $pricing->sale();
            foreach ([$sale?->from, $sale?->until] as $moment) {
                if ($moment !== null && $moment > $now && ($next === null || $moment < $next)) {
                    $next = $moment;
                }
            }
        }

        return $next;
    }

    /**
     * @param array<string, ?int>|null $stock how many of each item can be sold, when read for many offers at once
     */
    private function toOffer(Product $product, \DateTimeImmutable $now, ?array $stock = null): ProductOffer
    {
        $details = $product->details();
        $priceHidden = $details->priceVisibility->isHidden($this->settings->current()->hidePrices);
        $sellable = $this->content->isPublished($product->id()) && $details->purchasable && !$priceHidden;

        $attributes = array_map(static fn(array $offered): AttributeView => new AttributeView(
            $offered[0]->id(),
            $offered[0]->name(),
            $offered[0]->isRequired(),
            $offered[0]->isPriceHidden(),
            array_map(OptionView::fromOption(...), $offered[1]),
            $offered[0]->set()?->id(),
        ), $product->offeredOptions());
        $downloads = array_map(static fn(DownloadFile $file): string => $file->name(), $product->downloads());

        if ($product->hasVariations()) {
            $variations = array_map(fn(Variation $variation): OfferedVariation => $this->toVariation($variation, $details->backorders, $sellable, $now, $stock), $product->variations());
            $prices = array_map(static fn(OfferedVariation $variation): Money => Money::of($variation->price), $variations);
            usort($prices, static fn(Money $a, Money $b): int => $a->compare($b));
            $lowest = $prices[0] ?? $product->priceAt($now);
            $highest = $prices === [] ? $lowest : $prices[\count($prices) - 1];

            return new ProductOffer(
                $product->id(),
                $this->content->name($product->id()),
                $details->type->value,
                $details->sku,
                (string) $lowest,
                null,
                $highest->compare($lowest) === 0 ? null : (string) $highest,
                array_filter($variations, static fn(OfferedVariation $variation): bool => $variation->forSale) !== [],
                null,
                $details->featured,
                $attributes,
                $variations,
                $downloads,
                $priceHidden,
            );
        }

        [$available, $onBackorder] = $this->available($details->stockMode, StockItemRef::product($product->id()), $details->backorders, $stock);

        return new ProductOffer(
            $product->id(),
            $this->content->name($product->id()),
            $details->type->value,
            $details->sku,
            (string) $details->pricing->priceAt($now),
            self::regularPrice($details->pricing, $now),
            null,
            $sellable && $available !== 0,
            $available,
            $details->featured,
            $attributes,
            [],
            $downloads,
            $priceHidden,
            $onBackorder,
        );
    }

    /**
     * @param array<string, ?int>|null $stock
     */
    private function toVariation(Variation $variation, Backorders $productBackorders, bool $sellable, \DateTimeImmutable $now, ?array $stock = null): OfferedVariation
    {
        [$available, $onBackorder] = $this->available($variation->stockMode(), StockItemRef::variation($variation->id()), $variation->backorders() ?? $productBackorders, $stock);

        return new OfferedVariation(
            $variation->id(),
            $variation->signature(),
            implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options())),
            $variation->sku(),
            (string) $variation->pricing()->priceAt($now),
            self::regularPrice($variation->pricing(), $now),
            $sellable && $available !== 0,
            $available,
            $onBackorder,
        );
    }

    /**
     * How many can be sold, null when stock does not limit it (as with backorders); and whether it is out of
     * stock on a backorder the customer is told of.
     *
     * @param array<string, ?int>|null $stock read for many offers at once; otherwise the item's is read now
     *
     * @return array{?int, bool}
     */
    private function available(StockMode $mode, StockItemRef $ref, Backorders $backorders, ?array $stock = null): array
    {
        if ($mode !== StockMode::Tracked) {
            return [$mode === StockMode::Unavailable ? 0 : null, false];
        }
        $key = "{$ref->kind}:{$ref->id}";
        $inStock = $stock !== null && \array_key_exists($key, $stock) ? $stock[$key] : $this->stock->available([$ref]);
        if ($inStock === null || !$backorders->allowed()) {
            return [$inStock, false];
        }

        return [null, $backorders === Backorders::Notify && $inStock === 0];
    }

    private static function regularPrice(Pricing $pricing, \DateTimeImmutable $now): ?string
    {
        $price = $pricing->priceAt($now);

        return $price->compare($pricing->price()) < 0 ? (string) $pricing->price() : null;
    }
}
