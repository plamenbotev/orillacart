<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\TaxJurisdiction;

/**
 * A tax rate charged on part of a bill. Rates with the same key are one line of the invoice. A compound rate is
 * charged on the amount and the taxes listed before it.
 */
final readonly class Tax
{
    public function __construct(
        public string $key,
        public string $name,
        public string $percent,
        public bool $compound = false,
        /** Who it is owed to, as orders keep it per line and returns are filed. */
        public ?TaxJurisdiction $jurisdiction = null,
        /** Not charged: the customer's exemption covers the sale; the bill still records what it was on. */
        public bool $exempt = false,
    ) {}

    /**
     * What each rate charges on an amount, exact to four decimals, in the order of the rates. Of an amount that
     * includes them, each rate's part of it: the amount is shared out as the taxes on a price before tax would be.
     *
     * @param list<self> $taxes
     *
     * @return list<Money>
     */
    public static function parts(Money $amount, array $taxes, bool $included = false): array
    {
        if (!$included) {
            $parts = [];
            foreach ($taxes as $rate) {
                $parts[] = $rate->exempt ? Money::zero() : ($rate->compound ? array_reduce($parts, static fn(Money $sum, Money $part): Money => $sum->add($part), $amount) : $amount)->percent($rate->percent);
            }

            return $parts;
        }
        // A large price before tax shows the share of each rate in a price that includes them all.
        $reference = Money::of('1000000');
        $before = self::parts($reference, $taxes);
        $whole = array_reduce($before, static fn(Money $sum, Money $part): Money => $sum->add($part), $reference);

        return array_map(static fn(Money $part): Money => $amount->proportion($part, $whole), $before);
    }

    /**
     * What each rate is charged on, given its part: the amount, or for a compound rate the amount and the parts
     * before it. With prices that include tax, the part itself, which the bill adds up instead.
     *
     * @param list<self>  $taxes
     * @param list<Money> $parts as parts() gave them
     *
     * @return list<Money>
     */
    public static function bases(Money $amount, array $taxes, array $parts, bool $included = false): array
    {
        if ($included) {
            return $parts;
        }
        $bases = [];
        $before = $amount;
        foreach ($taxes as $index => $rate) {
            $bases[] = $rate->compound ? $before : $amount;
            $before = $before->add($parts[$index]);
        }

        return $bases;
    }

    /**
     * An amount that includes the rates, without them.
     *
     * @param list<self> $taxes
     */
    public static function without(Money $amount, array $taxes): Money
    {
        return array_reduce(self::parts($amount, $taxes, true), static fn(Money $left, Money $part): Money => $left->subtract($part), $amount);
    }
}
