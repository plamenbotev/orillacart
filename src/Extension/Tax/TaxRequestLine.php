<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * One line to rate: an item, the shipping or a fee, with what it costs before tax after its discounts.
 */
final readonly class TaxRequestLine
{
    public const string ITEM = 'item';
    public const string SHIPPING = 'shipping';
    public const string FEE = 'fee';

    /**
     * @param string      $id        names the line in the result: "item:0", "shipping", "fee:1"
     * @param string      $kind      ITEM, SHIPPING or FEE
     * @param string|null $taxCode   the shop's tax group of the line, by its id; null for the default group
     * @param string      $amount    what the line costs before tax, all of its quantity, decimal: "180.00"
     * @param int|null    $productId the product, for items
     * @param string|null $serviceCode what tax services call the goods, as the shop set it on the product or its
     *                                 tax group (Avalara's "PC040100", Stripe's "txcd_99999999"); null when it set
     *                                 none. Since version 9.
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public ?string $taxCode,
        public int $quantity,
        public string $amount,
        public ?int $productId = null,
        public ?string $sku = null,
        public ?string $serviceCode = null,
    ) {}
}
