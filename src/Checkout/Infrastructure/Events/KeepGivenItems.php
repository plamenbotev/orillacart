<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Infrastructure\Events;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Domain\Event\CartChanged;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\GivenItems;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A cart that changed gets the items its coupons give now, judged at the customer's saved address or the shop's,
 * as the cart page estimates; checkout judges again with the address given.
 */
final readonly class KeepGivenItems
{
    public function __construct(
        private GivenItems $given,
        private CheckoutService $checkout,
    ) {}

    #[AsEventListener]
    public function changed(CartChanged $event): void
    {
        $at = $this->checkout->estimateAt(new CartOwner(null, $event->customerId));
        $this->given->keep($event->cartId, $at === null ? null : new TaxAddress($at['country'], $at['state'], $at['postcode'], $at['city']));
    }
}
