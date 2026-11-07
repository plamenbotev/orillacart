<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something printed in one of the storefront's template slots (`slot('product_after_details')` in Twig, the
 * `orillacart_<slot>` actions): the scripted extensions' slot templates (design 56). Asked only when a slot
 * renders. The Platform's registrar hooks every slot once.
 */
#[AutoconfigureTag(self::TAG)]
interface SlotContent
{
    public const string TAG = 'orillacart.slot_content';

    /** The slots the storefront's templates have. */
    public const array SLOTS = ['account_after', 'cart_after', 'cart_after_totals', 'cart_before', 'checkout_after_billing', 'checkout_after_details', 'checkout_after_shipping', 'checkout_before_details', 'checkout_before_place_order', 'email_after_order', 'product_after_details', 'product_after_form', 'product_before_form', 'receipt_after'];

    /**
     * @param list<mixed> $arguments what the template gave the slot
     *
     * @return string HTML to print in the slot, '' for nothing
     */
    public function render(string $slot, array $arguments): string;
}
