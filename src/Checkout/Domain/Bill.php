<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * The totals of an order. Line amounts stay exact; each tax rate is charged on the sum of what it applies to
 * and rounded once to the currency, as are the subtotal, the discount and shipping, and the total is the sum of
 * those rounded parts, so an invoice always adds up. Discounts lower what tax is charged on: tax is on what the
 * customer pays.
 *
 * With prices that include tax, the amounts are what the customer pays, tax in them: each rate takes its part
 * of what it applies to, rounded once, and the total is the subtotal less discounts plus shipping, as entered.
 */
final class Bill
{
    private Money $subtotal;
    private Money $discount;
    private Money $shipping;

    /**
     * @var array<string, array{tax: Tax, items: Money, shipping: Money}> what each rate is charged on, or with
     *                                                                     prices that include tax, its exact part
     */
    private array $bases = [];

    public function __construct(
        private readonly int $decimals,
        private readonly bool $includesTax = false,
    ) {
        $this->subtotal = Money::zero();
        $this->discount = Money::zero();
        $this->shipping = Money::zero();
    }

    /**
     * @param list<Tax> $taxes    the rates charged on the line
     * @param Money|null $discount what coupons take off the line's total
     *
     * @return Money the line's tax, exact: what it shows, not what is billed
     */
    public function addItem(Money $total, array $taxes, ?Money $discount = null): Money
    {
        $discount ??= Money::zero();
        $this->subtotal = $this->subtotal->add($total);
        $this->discount = $this->discount->add($discount);
        $taxed = $total->subtract($discount);
        $percents = array_map(static fn(Tax $rate): string => $rate->percent, $taxes);
        $tax = Money::zero();
        foreach ($taxes as $rate) {
            $part = $this->includesTax ? $taxed->includedPercent($rate->percent, $percents) : $taxed->percent($rate->percent);
            $this->base($rate)['items'] = $this->base($rate)['items']->add($this->includesTax ? $part : $taxed);
            $tax = $tax->add($part);
        }

        return $tax;
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
        $percents = array_map(static fn(Tax $rate): string => $rate->percent, $taxes);
        foreach ($taxes as $rate) {
            $this->base($rate)['shipping'] = $this->base($rate)['shipping']->add($this->includesTax ? $price->includedPercent($rate->percent, $percents) : $price);
        }
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
        foreach ($this->bases as ['tax' => $tax, 'items' => $items, 'shipping' => $shipping]) {
            $billed = new BilledTax(
                $tax->name,
                $tax->percent,
                ($this->includesTax ? $items : $items->percent($tax->percent))->rounded($this->decimals),
                ($this->includesTax ? $shipping : $shipping->percent($tax->percent))->rounded($this->decimals),
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

    public function total(): Money
    {
        $total = $this->subtotal()->subtract($this->discount())->add($this->shipping());

        return $this->includesTax ? $total : $total->add($this->itemsTax())->add($this->shippingTax());
    }

    /**
     * @return array{tax: Tax, items: Money, shipping: Money}
     */
    private function &base(Tax $tax): array
    {
        $this->bases[$tax->key] ??= ['tax' => $tax, 'items' => Money::zero(), 'shipping' => Money::zero()];

        return $this->bases[$tax->key];
    }
}
