<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Configuration;
use OrillaCart\Catalog\Domain\DownloadFile;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\SettingsService;
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
        $available = $this->available($configuration);
        $measures = $configuration->measures();

        return new ItemQuote(
            $product->id(),
            $configuration->variation?->id(),
            $name,
            $configuration->sku(),
            $details->type->value,
            (string) $configuration->unitPrice,
            $details->taxable,
            $details->taxGroupId,
            $measures['weight'],
            array_map(static fn(AttributeOption $option): QuotedOption => new QuotedOption(
                $option->id(),
                $option->attribute()->name(),
                $option->name(),
                (string) $option->adjustmentOn($base),
            ), $configuration->options),
            $available,
            $this->content->isPublished($product->id()) && $details->purchasable && $available !== 0
                && !$details->priceVisibility->isHidden($this->settings->current()->hidePrices),
            $measures['length'],
            $measures['width'],
            $measures['height'],
            array_map(static fn(DownloadFile $file): QuotedDownload => new QuotedDownload($file->id(), $file->name(), $file->storageKey(), $file->mimeType()), $product->downloads()),
            $details->downloadLimit,
            $details->downloadExpiryDays,
            $details->shippingClassId,
        );
    }

    private function available(Configuration $configuration): ?int
    {
        return match ($configuration->stockMode()) {
            StockMode::AlwaysAvailable => null,
            StockMode::Unavailable => 0,
            StockMode::Tracked => $this->stock->available($configuration->variation !== null
                ? [StockItemRef::variation($configuration->variation->id())]
                : [StockItemRef::product($configuration->product->id()), ...array_map(static fn(AttributeOption $option): StockItemRef => StockItemRef::option($option->id()), $configuration->options)]),
        };
    }
}
