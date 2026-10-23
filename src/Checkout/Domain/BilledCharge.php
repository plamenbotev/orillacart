<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * One charge of a bill, an item, the shipping or a fee, with its taxes: each rate's share of what the rate bills
 * on that kind of charge, so the charges' taxes add up to the bill's to the cent.
 */
final readonly class BilledCharge
{
    public const string ITEM = 'item';
    public const string SHIPPING = 'shipping';
    public const string FEE = 'fee';

    /**
     * @param string           $kind   ITEM, SHIPPING or FEE
     * @param Money            $amount what tax is charged on: an item's total less its discount, exact
     * @param list<ChargedTax> $taxes
     */
    public function __construct(
        public string $kind,
        public string $label,
        public Money $amount,
        public array $taxes,
    ) {}

    public function tax(): Money
    {
        return array_reduce($this->taxes, static fn(Money $sum, ChargedTax $tax): Money => $sum->add($tax->amount), Money::zero());
    }
}
