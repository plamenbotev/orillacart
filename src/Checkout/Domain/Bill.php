<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * The totals of an order. Line amounts stay exact; each tax rate is charged on the sum of what it applies to
 * and rounded once to the currency, as are the subtotal and shipping, and the total is the sum of those
 * rounded parts, so an invoice always adds up.
 */
final class Bill
{
    private Money $subtotal;
    private Money $shipping;

    /** @var array<string, array{tax: Tax, items: Money, shipping: Money}> */
    private array $bases = [];

    public function __construct(private readonly int $decimals)
    {
        $this->subtotal = Money::zero();
        $this->shipping = Money::zero();
    }

    /**
     * @param list<Tax> $taxes the rates charged on the line
     *
     * @return Money the line's tax, exact: what it shows, not what is billed
     */
    public function addItem(Money $total, array $taxes): Money
    {
        $this->subtotal = $this->subtotal->add($total);
        $tax = Money::zero();
        foreach ($taxes as $rate) {
            $this->base($rate)['items'] = $this->base($rate)['items']->add($total);
            $tax = $tax->add($total->percent($rate->percent));
        }

        return $tax;
    }

    /**
     * @param list<Tax> $taxes the rates charged on shipping
     */
    public function ship(Money $price, array $taxes): void
    {
        $this->shipping = $price;
        foreach ($taxes as $rate) {
            $this->base($rate)['shipping'] = $this->base($rate)['shipping']->add($price);
        }
    }

    public function subtotal(): Money
    {
        return $this->subtotal->rounded($this->decimals);
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
                $items->percent($tax->percent)->rounded($this->decimals),
                $shipping->percent($tax->percent)->rounded($this->decimals),
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
        return $this->subtotal()->add($this->itemsTax())->add($this->shipping())->add($this->shippingTax());
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
