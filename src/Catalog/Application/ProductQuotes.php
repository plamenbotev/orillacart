<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\Configuration;
use OrillaCart\Catalog\Domain\DownloadFile;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\PricedItem;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * What a product costs with the customer's choices, and how many can be sold: the cart's view of the catalog.
 */
final readonly class ProductQuotes
{
    public function __construct(
        private ProductRepository $products,
        private ProductContent $content,
        private StockService $stock,
        private ClockInterface $clock,
        private SettingsService $settings,
        private PriceResolver $prices,
    ) {}

    /**
     * @throws ProductNotFound  also for a product post whose commerce details were never saved
     * @throws InvalidSelection
     */
    public function quote(QuoteRequest $request): ItemQuote
    {
        $product = $this->products->find($request->productId) ?? throw new ProductNotFound($request->productId);
        $now = $this->clock->now();
        $configuration = $product->configure($request->optionIds, $now);
        $base = $product->priceAt($now);
        $details = $product->details();

        $name = $this->content->name($product->id());
        if ($configuration->variation !== null) {
            $name .= ' – ' . implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $configuration->variation->options()));
        }
        // Below zero by what is owed already, for the backorder limit.
        $level = $this->available($configuration);
        $available = $level === null ? null : max(0, $level);
        $backorders = $configuration->backorders();
        $inStock = null;
        $backorderLimit = $backorders->allowed() ? $configuration->backorderLimit() : null;
        if ($backorders->allowed() && $configuration->stockMode() === StockMode::Tracked && $level !== null) {
            // Backorders lift the limit, up to how many it may owe; how many are in stock tells what will be owed.
            $inStock = $available;
            $available = $backorderLimit === null ? null : max(0, $level + $backorderLimit);
        }
        $measures = $configuration->measures();
        $pricing = $configuration->variation?->pricing() ?? $details->pricing;
        $terms = $this->content->termIds($product->id());
        $onSale = $pricing->priceAt($now)->compare($pricing->price()) < 0;
        // The shop's price rules change the product's (or variation's) price; options add their amounts, and
        // their percentages of the price the shopper pays.
        $own = $pricing->priceAt($now);
        $ruled = $this->prices->forShopper($request->customerId)->price($own, new PricedItem($product->id(), $onSale, $terms['categories'], $terms['brands']), max(1, $request->quantity), $configuration->variation?->id(), $pricing->price());
        $unitPrice = array_reduce($configuration->options, static fn(Money $price, AttributeOption $option): Money => $price->add($option->adjustmentOn($ruled)), $ruled)->atLeastZero();
        $weight = $measures['weight'];
        $downloads = array_map(static fn(DownloadFile $file): QuotedDownload => new QuotedDownload($file->id(), $file->name(), $file->storageKey(), $file->mimeType()), $product->downloads());
        $contents = [];
        $forSale = true;
        $bundle = $product->bundle();
        if ($bundle === null && ($request->bundleChoices ?? []) !== []) {
            throw new InvalidSelection('Only a bundle offers choices of its items.');
        }
        if ($bundle !== null) {
            // A bundle is sold while each item is, as many as its scarcest item allows; its weight is its items'
            // unless it has its own (and then the optional items' on top), and the buyer gets every item's downloads.
            $available = $inStock = null;
            $sum = Money::zero();
            $itemsWeight = Money::zero();
            $extras = $extrasWeight = Money::zero();
            foreach ($bundle->select($request->bundleChoices) as [$item, $variationId]) {
                $quote = $this->quote(new QuoteRequest($item->productId, $this->signature($item->productId, $variationId), $item->quantity * max(1, $request->quantity), $request->customerId));
                $forSale = $forSale && $quote->forSale;
                if ($quote->available !== null) {
                    $available = min($available ?? PHP_INT_MAX, intdiv(max(0, $quote->available), $item->quantity));
                }
                if ($quote->inStock !== null) {
                    $inStock = min($inStock ?? PHP_INT_MAX, intdiv(max(0, $quote->inStock), $item->quantity));
                }
                $price = Money::of($quote->unitPrice)->times($item->quantity);
                $sum = $sum->add($price);
                if ($item->optional) {
                    $extras = $extras->add($price);
                }
                $itemWeight = Money::of($quote->weight ?? '0')->times($item->quantity);
                $itemsWeight = $itemsWeight->add($itemWeight);
                if ($item->optional) {
                    $extrasWeight = $extrasWeight->add($itemWeight);
                }
                array_push($downloads, ...$quote->downloads);
                $contents[] = new QuotedBundleItem($quote->productId, $quote->variationId, $quote->name, $item->quantity, $quote->available !== null || $quote->inStock !== null, $quote->backorders, $quote->backorderLimit, (string) $price, $quote->taxable, $quote->taxGroupId);
            }
            // A fixed price covers the items every bundle holds; the optional ones the customer adds cost their own.
            $unitPrice = $bundle->pricing === Bundle::SUM ? $bundle->priceOf($sum) : $unitPrice->add($extras);
            $weight = $weight === null ? ($itemsWeight->isZero() ? null : (string) $itemsWeight) : (string) Money::of($weight)->add($extrasWeight);
            $backorders = Backorders::No;
        }
        // What the answers to the product's forms cost comes on top, as the shop priced it (an engraving).
        $unitPrice = $unitPrice->add(Money::of($request->extra));
        // After every adjustment, in the shop's decimals: what the cart shows is what the order charges.
        $unitPrice = $unitPrice->rounded($this->settings->current()->priceDecimals);

        return new ItemQuote(
            $product->id(),
            $configuration->variation?->id(),
            $name,
            $configuration->sku(),
            $details->type->value,
            (string) $unitPrice,
            $details->taxable,
            $details->taxGroupId,
            $weight,
            array_map(static fn(AttributeOption $option): QuotedOption => new QuotedOption(
                $option->id(),
                $option->attribute()->name(),
                $option->name(),
                (string) $option->adjustmentOn($ruled),
            ), $configuration->options),
            $available,
            $forSale && $this->content->isPublished($product->id()) && $details->purchasable && $available !== 0
                && !$details->priceVisibility->isHidden($this->settings->current()->hidePrices),
            $measures['length'],
            $measures['width'],
            $measures['height'],
            $downloads,
            $details->downloadLimit,
            $details->downloadExpiryDays,
            $details->shippingClassId,
            $backorders->value,
            $inStock,
            $onSale,
            $terms['categories'],
            $terms['brands'],
            $contents,
            $bundle === null ? $backorderLimit : null,
        );
    }

    /**
     * The options that pick a variation, for quoting it as a bundle's item.
     *
     * @return list<int>
     */
    private function signature(int $productId, ?int $variationId): array
    {
        if ($variationId === null) {
            return [];
        }
        foreach ($this->products->find($productId)?->variations() ?? [] as $variation) {
            if ($variation->id() === $variationId) {
                return $variation->signature();
            }
        }

        throw new ProductNotFound($productId);
    }

    private function available(Configuration $configuration): ?int
    {
        return match ($configuration->stockMode()) {
            StockMode::AlwaysAvailable => null,
            StockMode::Unavailable => 0,
            StockMode::Tracked => $this->stock->available($configuration->variation !== null
                ? [StockItemRef::variation($configuration->variation->id())]
                : [StockItemRef::product($configuration->product->id()), ...array_map(static fn(AttributeOption $option): StockItemRef => StockItemRef::option($option->id()), $configuration->options)], owedBelowZero: true),
        };
    }
}
