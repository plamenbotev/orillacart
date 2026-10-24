<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * Command: give the money back for the items that came back.
 */
final readonly class RefundReturn
{
    public function __construct(
        /** The shop gave the money back itself, not through the payment provider. */
        public bool $outside = false,
        /** The items go back on sale; not so one that came back broken. */
        public bool $restock = true,
        /** As store credit, or for a guest a gift card, instead of the money. */
        public bool $storeCredit = false,
    ) {}
}
