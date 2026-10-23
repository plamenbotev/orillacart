<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * The totals of an order. Line amounts stay exact; each tax rate is charged on the sum of what it applies to
 * and rounded once to the currency, as are the subtotal, the discount, shipping and the fees, and the total is
 * the sum of those rounded parts, so an invoice always adds up. Discounts lower what tax is charged on: tax is on what the
 * customer pays.
 *
 * With prices that include tax, the amounts are what the customer pays, tax in them: each rate takes its part
 * of what it applies to, rounded once, and the total is the subtotal less discounts plus shipping and fees, as
 * entered.
 *
 * Every charge, an item, the shipping or a fee, gets its share of what each rate bills on its kind of charge, in
 * the currency's decimals, so the charges add up to the bill to the cent: orders keep each rate on each line, as
 * returns are filed by jurisdiction and refunds give back what each line paid.
 *
 * A shop may round each line's tax instead: then each rate bills the sum of the lines' rounded taxes.
 *
 * A compound rate is charged on the amount and the taxes listed before it.
 */
final class Bill
{
    private Money $subtotal;
    private Money $discount;
    private Money $shipping;

    /** @var list<array{label: string, amount: Money}> */
    private array $fees = [];

    /**
     * @var array<string, array{tax: Tax, on: array<string, Money>}> what each rate is charged on, by kind of charge,
     *                                                               or with prices that include tax, its exact part
     */
    private array $bases = [];

    /**
     * @var list<array{kind: string, label: string, amount: Money, rates: list<Tax>, parts: array<string, Money>, taxable: array<string, Money>}>
     *      each charge, with its exact tax and what it is charged on, by rate
     */
    private array $charges = [];

    public function __construct(
        private readonly int $decimals,
        private readonly bool $includesTax = false,
        private readonly bool $roundsEachLine = false,
    ) {
        $this->subtotal = Money::zero();
        $this->discount = Money::zero();
        $this->shipping = Money::zero();
    }

    /**
     * @param list<Tax>  $taxes    the rates charged on the line
     * @param Money|null $discount what coupons take off the line's total
     *
     * @return int the line's place, for its tax among lineTaxes()
     */
    public function addItem(Money $total, array $taxes, ?Money $discount = null, string $label = ''): int
    {
        $discount ??= Money::zero();
        $this->subtotal = $this->subtotal->add($total);
        $this->discount = $this->discount->add($discount);
        $this->charge(BilledCharge::ITEM, $label, $total->subtract($discount), $taxes);

        return \count(array_filter($this->charges, static fn(array $charge): bool => $charge['kind'] === BilledCharge::ITEM)) - 1;
    }

    /**
     * Each line's tax, in the order the lines were added.
     *
     * @return list<Money>
     */
    public function lineTaxes(): array
    {
        return array_map(static fn(BilledCharge $charge): Money => $charge->tax(), $this->charges(BilledCharge::ITEM));
    }

    public function includesTax(): bool
    {
        return $this->includesTax;
    }

    /**
     * @param list<Tax> $taxes the rates charged on shipping
     */
    public function ship(Money $price, array $taxes, string $label = ''): void
    {
        if (array_filter($this->charges, static fn(array $charge): bool => $charge['kind'] === BilledCharge::SHIPPING) !== []) {
            throw new \LogicException('A bill ships once.');
        }
        $this->shipping = $price;
        $this->charge(BilledCharge::SHIPPING, $label, $price, $taxes);
    }

    /**
     * A fee the order pays besides its items and shipping, such as for its way to pay.
     *
     * @param list<Tax> $taxes the rates charged on it
     */
    public function addFee(string $label, Money $amount, array $taxes): void
    {
        $amount = $amount->rounded($this->decimals);
        $this->fees[] = ['label' => $label, 'amount' => $amount];
        $this->charge(BilledCharge::FEE, $label, $amount, $taxes);
    }

    /**
     * The charges with their taxes: the items in the order they were added, then the shipping, then the fees.
     *
     * @param string|null $kind only those of this kind
     *
     * @return list<BilledCharge>
     */
    public function charges(?string $kind = null): array
    {
        $shares = [];
        foreach ([BilledCharge::ITEM, BilledCharge::SHIPPING, BilledCharge::FEE] as $of) {
            foreach (array_keys($this->bases) as $key) {
                foreach ($this->shares($this->billed((string) $key, $of), (string) $key, $of) as $index => $share) {
                    $shares[$index][$key] = $share;
                }
            }
        }
        $charges = [];
        foreach ([BilledCharge::ITEM, BilledCharge::SHIPPING, BilledCharge::FEE] as $of) {
            foreach ($this->charges as $index => $charge) {
                if ($charge['kind'] !== $of || ($kind !== null && $kind !== $of)) {
                    continue;
                }
                $taxes = [];
                foreach ($charge['rates'] as $rate) {
                    $taxes[] = new ChargedTax($rate, $charge['taxable'][$rate->key] ?? Money::zero(), $shares[$index][$rate->key] ?? Money::zero());
                }
                $charges[] = new BilledCharge($of, $charge['label'], $charge['amount'], $taxes);
            }
        }

        return $charges;
    }

    /**
     * @return list<array{label: string, amount: Money}> each in the currency's decimals
     */
    public function fees(): array
    {
        return $this->fees;
    }

    public function feesTotal(): Money
    {
        return array_reduce($this->fees, static fn(Money $sum, array $fee): Money => $sum->add($fee['amount']), Money::zero());
    }

    public function subtotal(): Money
    {
        return $this->subtotal->rounded($this->decimals);
    }

    /** What coupons take off the items, in all. */
    public function discount(): Money
    {
        return $this->discount->rounded($this->decimals);
    }

    public function shipping(): Money
    {
        return $this->shipping->rounded($this->decimals);
    }

    /**
     * @return list<BilledTax> in the order the rates first appeared; rates that charge nothing are left out
     */
    public function taxes(): array
    {
        $taxes = [];
        foreach ($this->bases as $key => ['tax' => $tax]) {
            $billed = new BilledTax(
                $tax->name,
                $tax->percent,
                $this->billed((string) $key, BilledCharge::ITEM),
                $this->billed((string) $key, BilledCharge::SHIPPING),
                $this->billed((string) $key, BilledCharge::FEE),
            );
            if (!$billed->total()->isZero()) {
                $taxes[] = $billed;
            }
        }

        return $taxes;
    }

    public function itemsTax(): Money
    {
        return array_reduce($this->taxes(), static fn(Money $sum, BilledTax $tax): Money => $sum->add($tax->items), Money::zero());
    }

    public function shippingTax(): Money
    {
        return array_reduce($this->taxes(), static fn(Money $sum, BilledTax $tax): Money => $sum->add($tax->shipping), Money::zero());
    }

    public function feesTax(): Money
    {
        return array_reduce($this->taxes(), static fn(Money $sum, BilledTax $tax): Money => $sum->add($tax->fees), Money::zero());
    }

    public function total(): Money
    {
        $total = $this->subtotal()->subtract($this->discount())->add($this->shipping())->add($this->feesTotal());

        return $this->includesTax ? $total : $total->add($this->itemsTax())->add($this->shippingTax())->add($this->feesTax());
    }

    /**
     * @param list<Tax> $taxes
     */
    private function charge(string $kind, string $label, Money $amount, array $taxes): void
    {
        $exact = Tax::parts($amount, $taxes, $this->includesTax);
        $bases = Tax::bases($amount, $taxes, $exact, $this->includesTax);
        // What each rate is charged on before tax, as returns report it.
        $net = $this->includesTax ? array_reduce($exact, static fn(Money $left, Money $part): Money => $left->subtract($part), $amount) : $amount;
        $taxable = $this->includesTax ? Tax::bases($net, $taxes, Tax::parts($net, $taxes), false) : $bases;
        $parts = [];
        $on = [];
        foreach ($taxes as $index => $rate) {
            $on[$rate->key] = $taxable[$index];
            if ($rate->exempt) {
                // Not charged: only what it was on is kept, for exempt sales.
                continue;
            }
            $this->base($rate)['on'][$kind] = ($this->base($rate)['on'][$kind] ?? Money::zero())->add($bases[$index]);
            $parts[$rate->key] = ($parts[$rate->key] ?? Money::zero())->add($exact[$index]);
        }
        $this->charges[] = ['kind' => $kind, 'label' => $label, 'amount' => $amount, 'rates' => $taxes, 'parts' => $parts, 'taxable' => $on];
    }

    /**
     * What a rate bills on a kind of charge, in the currency's decimals: once on their sum, or for items with
     * each line rounded, the sum of each line's rounded tax.
     */
    private function billed(string $key, string $kind): Money
    {
        if ($kind === BilledCharge::ITEM && $this->roundsEachLine) {
            return array_reduce(
                array_filter($this->charges, static fn(array $charge): bool => $charge['kind'] === BilledCharge::ITEM),
                fn(Money $sum, array $charge): Money => $sum->add(($charge['parts'][$key] ?? Money::zero())->rounded($this->decimals)),
                Money::zero(),
            );
        }
        $base = $this->bases[$key]['on'][$kind] ?? Money::zero();

        return ($this->includesTax ? $base : $base->percent($this->bases[$key]['tax']->percent))->rounded($this->decimals);
    }

    /**
     * A rate's billed tax on a kind of charge, shared out by their exact parts: rounded down to the currency, and
     * the cents left over go to the charges whose parts lost the most to rounding.
     *
     * @return array<int, Money> by the charge's place among all charges
     */
    private function shares(Money $billed, string $key, string $kind): array
    {
        $step = 10 ** (Money::DECIMALS - $this->decimals);
        $floors = [];
        $remainders = [];
        foreach ($this->charges as $index => $charge) {
            if ($charge['kind'] !== $kind || !isset($charge['parts'][$key])) {
                continue;
            }
            $exact = $charge['parts'][$key]->tenThousandths;
            $floors[$index] = $kind === BilledCharge::ITEM && $this->roundsEachLine ? $charge['parts'][$key]->rounded($this->decimals)->tenThousandths : intdiv($exact, $step) * $step;
            $remainders[$index] = $exact - $floors[$index];
        }
        if ($floors === []) {
            return [];
        }
        $left = intdiv($billed->tenThousandths - array_sum($floors), $step);
        // Largest remainders first for cents to give, smallest first for cents to take back; ties by charge.
        $order = array_keys($remainders);
        usort($order, static fn(int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        if ($left < 0) {
            $order = array_values(array_reverse(array_filter($order, static fn(int $index): bool => $floors[$index] > 0)));
        }
        for ($i = 0; $i < abs($left) && $order !== []; ++$i) {
            $floors[$order[$i % \count($order)]] += $left > 0 ? $step : -$step;
        }

        return array_map(Money::fromTenThousandths(...), $floors);
    }

    /**
     * @return array{tax: Tax, on: array<string, Money>}
     */
    private function &base(Tax $tax): array
    {
        $this->bases[$tax->key] ??= ['tax' => $tax, 'on' => []];

        return $this->bases[$tax->key];
    }
}
