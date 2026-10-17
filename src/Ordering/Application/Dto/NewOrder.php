<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * Command from checkout: record a placed order. Amounts are decimal strings rounded to the currency.
 */
final readonly class NewOrder
{
    /**
     * @param list<NewOrderLine> $lines
     * @param list<TaxLineView>  $taxes
     */
    public function __construct(
        public ?int $customerId,
        public string $email,
        public AddressData $billing,
        public ?AddressData $shippingAddress,
        public array $lines,
        public ?NewShipping $shipping,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $total,
        public string $currency,
        public ?string $paymentGateway,
        public string $paymentName,
        public string $paymentNote,
        public string $note,
    ) {}
}
