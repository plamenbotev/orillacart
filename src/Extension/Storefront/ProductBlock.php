<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * A block an add-on gives product pages, product cards and landing pages, beside the shop's own: a size guide, a
 * delivery estimate, a badge. The shop registers it as "orillacart-addon/{id}" (underscores become dashes), gives
 * it the product it renders for (the page's, the card's, or the one its Product setting names), shows it in the
 * editor with the shop's product blocks, and lets it into the Products block's card. The owner places it like any
 * block. Since version 16.
 *
 *     $registry->productBlock(new class implements ProductBlock { … });
 */
interface ProductBlock
{
    /** Lowercase letters, digits and underscores, up to 40, starting with the add-on's own prefix: "sizes_guide". */
    public function id(): string;

    /** The block's name in the editor's inserter: "Size guide". */
    public function title(): string;

    /** What it shows, in the inserter. */
    public function description(): string;

    /**
     * The block's HTML for the product, escaped by the add-on; an empty string shows nothing.
     *
     * @param array<string, mixed> $settings the block's attributes the editor stored (its own and productId)
     */
    public function render(ShownProduct $product, array $settings): string;
}
