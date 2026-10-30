<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain\Event;

/**
 * An order was placed from a cart left at checkout: the cart counts recovered once the order is paid, which it
 * may be already.
 */
final readonly class LeftCartOrdered
{
    public function __construct(public int $orderId) {}
}
