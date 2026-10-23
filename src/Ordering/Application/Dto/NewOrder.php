<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * Command from checkout: record a placed order. Amounts are decimal strings rounded to the currency.
 */
final readonly class NewOrder
{
    /**
     * @param list<NewOrderLine>   $lines  the items, the shipping and the fees
     * @param list<OrderFieldView> $fields answers to add-ons' checkout fields
     * @param array{reason: string, certificate: string}|null $taxExemption the customer's exemption that covered it
     */
    public function __construct(
        public ?int $customerId,
        public string $email,
        public AddressData $billing,
        public ?AddressData $shippingAddress,
        public array $lines,
        public ?NewShipping $shipping,
        public string $total,
        public string $currency,
        public ?string $paymentGateway,
        public string $paymentName,
        public string $paymentNote,
        public string $note,
        public array $fields = [],
        /** The amounts include tax: the total is not the subtotal plus tax. */
        public bool $pricesIncludeTax = false,
        /** The business customer's VAT number, as checked. */
        public ?string $vatNumber = null,
        /** Sold without VAT: the customer accounts for it (reverse charge). */
        public bool $reverseCharge = false,
        /** The language the customer ordered in; null for the site's. */
        public ?string $locale = null,
        /** The day the customer chose for delivery, "2026-10-21", with the time of day and what it counts against. */
        public ?string $deliveryDate = null,
        public ?string $deliverySlot = null,
        public ?string $deliveryKey = null,
        public ?array $taxExemption = null,
        /** The tax service that worked out its taxes, by its id; null for the shop's own rates. */
        public ?string $taxProvider = null,
        /** The import scheme its goods came under (ImportScheme), and the shop's number there when it charged the tax. */
        public ?string $importScheme = null,
        public ?string $importNumber = null,
    ) {}
}
