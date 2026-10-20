<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * Command: what an order on hold contains now, as the shop repriced it.
 */
final readonly class NewOrderItems
{
    /**
     * @param list<NewOrderLine> $lines
     * @param list<TaxLineView>  $taxes
     * @param list<OrderDiscountView> $discounts the order's coupons, priced again
     * @param list<NewOrderFee>       $fees      its fees, priced again
     */
    public function __construct(
        public array $lines,
        public ?NewShipping $shipping,
        public ?AddressData $shippingAddress,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $total,
        public string $discountTotal = '0',
        public array $discounts = [],
        public bool $pricesIncludeTax = false,
        public array $fees = [],
        public string $feesTax = '0',
    ) {}
}
