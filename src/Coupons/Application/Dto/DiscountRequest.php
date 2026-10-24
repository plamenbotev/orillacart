<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application\Dto;

/**
 * An order being priced: its items, and who orders them to where, as far as known.
 */
final readonly class DiscountRequest
{
    /**
     * @param list<DiscountItem> $items
     * @param string|null        $country the shipping country; country rules wait until it is known
     * @param string|null        $email   the customer's; limits per customer and first orders wait until it is known
     * @param int|null           $orderId the order being priced again, when the shop edits it: not an earlier order
     */
    public function __construct(
        public array $items,
        public string $currency,
        public ?string $country = null,
        public ?int $customerId = null,
        public ?string $email = null,
        public ?int $orderId = null,
        /** Units of the order's currency for one of the shop's: coupons' amounts are the shop's. */
        public string $rate = '1',
        /** @var list<string>|null the automatic discounts that may apply, by code: an order edited keeps its own; null for every one the shop has now */
        public ?array $automatic = null,
    ) {}
}
