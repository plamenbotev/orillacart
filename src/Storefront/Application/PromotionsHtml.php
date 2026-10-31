<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Cart\Application\Dto\CartOwner;

/**
 * The promotions the shopper's cart gets and could still get, as the Promotion progress block shows them: for the
 * storefront script, which fills the block in on pages a cache may keep, and again as the cart changes.
 */
interface PromotionsHtml
{
    /** Every promotion to tell of, as list items the script trims to each block's settings; empty for none. */
    public function render(CartOwner $owner): string;
}
