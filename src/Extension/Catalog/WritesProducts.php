<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * Makes and changes the shop's products as its import does (API 26): a catalogue kept in step with a supplier or an
 * ERP, a product made for a quote, a test's own product. A new product is a draft, seen by nobody but the shop,
 * until publish(). Every value is checked before anything is written, so a refused call changes nothing.
 */
interface WritesProducts
{
    /**
     * @throws \InvalidArgumentException without a name or a price, for a value the shop cannot take, or a SKU another has
     */
    public function create(ProductDraft $draft): ProductSummary;

    /**
     * @throws \InvalidArgumentException for no such product, or a value the shop cannot take
     */
    public function change(int $productId, ProductDraft $changes): ProductSummary;

    /**
     * @throws \InvalidArgumentException for no such product, an option the product does not offer, or a variation it has already
     */
    public function addVariation(int $productId, VariationDraft $draft): ProductVariationSummary;

    /** @throws \InvalidArgumentException for no such variation of the product, or a value the shop cannot take */
    public function changeVariation(int $productId, int $variationId, VariationDraft $changes): ProductVariationSummary;

    /** Puts the product on sale: shoppers see it. */
    public function publish(int $productId): void;

    /** Takes it back to a draft: shoppers no longer see it. */
    public function unpublish(int $productId): void;

    /** Moves it to the trash; placed orders keep it, and the shop can restore it. */
    public function trash(int $productId): void;
}
