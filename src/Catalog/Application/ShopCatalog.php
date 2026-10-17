<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Domain\AttributeOption;
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
        $offers = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $offers[$id] = $this->toOffer($found[$id], $now);
            }
        }

        return $offers;
    }

    private function toOffer(Product $product, \DateTimeImmutable $now): ProductOffer
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
            $variations = array_map(fn(Variation $variation): OfferedVariation => $this->toVariation($variation, $sellable, $now), $product->variations());
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

        $available = $this->available($details->stockMode, StockItemRef::product($product->id()));

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
        );
    }

    private function toVariation(Variation $variation, bool $sellable, \DateTimeImmutable $now): OfferedVariation
    {
        $available = $this->available($variation->stockMode(), StockItemRef::variation($variation->id()));

        return new OfferedVariation(
            $variation->id(),
            $variation->signature(),
            implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options())),
            $variation->sku(),
            (string) $variation->pricing()->priceAt($now),
            self::regularPrice($variation->pricing(), $now),
            $sellable && $available !== 0,
            $available,
        );
    }

    private function available(StockMode $mode, StockItemRef $ref): ?int
    {
        return match ($mode) {
            StockMode::AlwaysAvailable => null,
            StockMode::Unavailable => 0,
            StockMode::Tracked => $this->stock->available([$ref]),
        };
    }

    private static function regularPrice(Pricing $pricing, \DateTimeImmutable $now): ?string
    {
        $price = $pricing->priceAt($now);

        return $price->compare($pricing->price()) < 0 ? (string) $pricing->price() : null;
    }
}
