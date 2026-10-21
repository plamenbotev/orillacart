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
 * Each line's tax is its share of what each rate bills on the items, in the currency's decimals, so the lines
 * add up to the bill to the cent.
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
     * @var array<string, array{tax: Tax, items: Money, shipping: Money, fees: Money}> what each rate is charged on,
     *                                                                                 or with prices that include
     *                                                                                 tax, its exact part
     */
    private array $bases = [];

    /** @var list<array<string, Money>> each line's exact tax, by rate */
    private array $lineParts = [];

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
     * @param list<Tax> $taxes    the rates charged on the line
     * @param Money|null $discount what coupons take off the line's total
     *
     * @return int the line's place, for its tax among lineTaxes()
     */
    public function addItem(Money $total, array $taxes, ?Money $discount = null): int
    {
        $discount ??= Money::zero();
        $this->subtotal = $this->subtotal->add($total);
        $this->discount = $this->discount->add($discount);
        $taxed = $total->subtract($discount);
        $exact = Tax::parts($taxed, $taxes, $this->includesTax);
        $bases = Tax::bases($taxed, $taxes, $exact, $this->includesTax);
        $parts = [];
        foreach ($taxes as $index => $rate) {
            $this->base($rate)['items'] = $this->base($rate)['items']->add($bases[$index]);
            $parts[$rate->key] = ($parts[$rate->key] ?? Money::zero())->add($exact[$index]);
        }
        $this->lineParts[] = $parts;

        return \count($this->lineParts) - 1;
    }

    /**
     * Each line's tax, in the order the lines were added: its share of each rate's billed items tax. A rate's
     * tax goes to the lines by their exact parts, rounded down to the currency, and the cents left over go to
     * the lines whose parts lost the most to rounding.
     *
     * @return list<Money>
     */
    public function lineTaxes(): array
    {
        $taxes = array_fill(0, \count($this->lineParts), Money::zero());
        if ($this->roundsEachLine) {
            foreach ($this->lineParts as $line => $parts) {
                foreach ($parts as $part) {
                    $taxes[$line] = $taxes[$line]->add($part->rounded($this->decimals));
                }
            }

            return array_values($taxes);
        }
        $step = 10 ** (Money::DECIMALS - $this->decimals);
        foreach (array_keys($this->bases) as $key) {
            $billed = $this->billedItems((string) $key)->tenThousandths;
            $floors = [];
            $remainders = [];
            foreach ($this->lineParts as $line => $parts) {
                $exact = ($parts[$key] ?? Money::zero())->tenThousandths;
                $floors[$line] = intdiv($exact, $step) * $step;
                $remainders[$line] = $exact - $floors[$line];
            }
            $left = intdiv($billed - array_sum($floors), $step);
            // Largest remainders first for cents to give, smallest first for cents to take back; ties by line.
            $order = array_keys($remainders);
            usort($order, static fn(int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
            if ($left < 0) {
                $order = array_reverse(array_filter($order, static fn(int $line): bool => $floors[$line] > 0));
            }
            for ($i = 0; $i < abs($left) && $order !== []; ++$i) {
                $floors[$order[$i % \count($order)]] += $left > 0 ? $step : -$step;
            }
            foreach ($floors as $line => $share) {
                $taxes[$line] = $taxes[$line]->add(Money::fromTenThousandths($share));
            }
        }

        return array_values($taxes);
    }

    public function includesTax(): bool
    {
        return $this->includesTax;
    }

    /**
     * @param list<Tax> $taxes the rates charged on shipping
     */
    public function ship(Money $price, array $taxes): void
    {
        $this->shipping = $price;
        $bases = Tax::bases($price, $taxes, Tax::parts($price, $taxes, $this->includesTax), $this->includesTax);
        foreach ($taxes as $index => $rate) {
            $this->base($rate)['shipping'] = $this->base($rate)['shipping']->add($bases[$index]);
        }
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
        $bases = Tax::bases($amount, $taxes, Tax::parts($amount, $taxes, $this->includesTax), $this->includesTax);
        foreach ($taxes as $index => $rate) {
            $this->base($rate)['fees'] = $this->base($rate)['fees']->add($bases[$index]);
        }
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
        foreach ($this->bases as $key => ['tax' => $tax, 'shipping' => $shipping, 'fees' => $fees]) {
            $billed = new BilledTax(
                $tax->name,
                $tax->percent,
                $this->billedItems((string) $key),
                ($this->includesTax ? $shipping : $shipping->percent($tax->percent))->rounded($this->decimals),
                ($this->includesTax ? $fees : $fees->percent($tax->percent))->rounded($this->decimals),
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
     * What a rate bills on the items, in the currency's decimals: once on their sum, or the sum of each line's
     * rounded tax.
     */
    private function billedItems(string $key): Money
    {
        if ($this->roundsEachLine) {
            return array_reduce($this->lineParts, fn(Money $sum, array $parts): Money => $sum->add(($parts[$key] ?? Money::zero())->rounded($this->decimals)), Money::zero());
        }
        ['tax' => $tax, 'items' => $items] = $this->bases[$key];

        return ($this->includesTax ? $items : $items->percent($tax->percent))->rounded($this->decimals);
    }

    /**
     * @return array{tax: Tax, items: Money, shipping: Money, fees: Money}
     */
    private function &base(Tax $tax): array
    {
        $this->bases[$tax->key] ??= ['tax' => $tax, 'items' => Money::zero(), 'shipping' => Money::zero(), 'fees' => Money::zero()];

        return $this->bases[$tax->key];
    }
}
