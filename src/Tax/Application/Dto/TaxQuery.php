<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * Query: which taxes apply to an item of a tax group, sold to a customer with these addresses.
 * Without a group, the shop's default group applies; addresses not known yet are null.
 */
final readonly class TaxQuery
{
    public function __construct(
        public ?int $groupId = null,
        public ?TaxAddress $shipping = null,
        public ?TaxAddress $billing = null,
        /** For shipping: rates that leave shipping alone do not apply. */
        public bool $forShipping = false,
        /** Taxed at the shipping address whatever the shop's basis, as goods under an import scheme are. */
        public bool $atShipping = false,
    ) {}
}
