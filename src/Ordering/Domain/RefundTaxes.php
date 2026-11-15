<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;

/**
 * The tax a refund gives back: the share of each line it covers, and of each rate. Arithmetic on the order as it
 * is; the lines and tax rows record what they gave back.
 */
final class RefundTaxes
{
    /**
     * The tax inside a refund's amount, given back on the lines it covers: the refunded items (by quantity) and
     * shipping, or every line when it names neither, each line's rates by what they charged. The refund's tax
     * is the share the lines' tax is of what they cost; never more than is left of the order's tax, and the
     * refund that empties the order gives back all of it.
     */
    public static function share(Order $order, OrderRefund $refund): Money
    {
        $covered = [];
        foreach ($order->byQuantity() as $line) {
            $quantity = $refund->quantities[$line->position()] ?? 0;
            if ($quantity > 0) {
                $covered[] = [$line, ...self::lineShare($order, $line, $quantity)];
            }
        }
        $shipping = $order->shippingLine();
        if ($refund->shipping && $shipping !== null) {
            $covered[] = [$shipping, ...self::lineShare($order, $shipping, 1)];
        }
        if ($covered === []) {
            foreach ($order->allLines() as $line) {
                $covered[] = [$line, ...self::lineShare($order, $line, $line->quantity())];
            }
        }
        // In the currency's cents, line by line: a credit note and the taxes report show 0.83 of 5 on 24, not 0.8333.
        $decimals = new Currency($order->currency())->decimals();
        $gross = Money::sum($covered, static fn(array $share): Money => $share[1]);
        $tax = Money::sum($covered, static fn(array $share): Money => $share[2]);
        $left = $refund->amount;
        foreach ($covered as $index => [$line, $lineGross]) {
            $part = $index === \count($covered) - 1 ? $left : ($gross->isZero() ? Money::zero() : $refund->amount->proportion($lineGross, $gross)->rounded($decimals));
            $left = $left->subtract($part);
            $line->refunded($part);
        }

        $taxLeft = $order->tax()->subtract($order->refundedTax())->atLeastZero();
        if ($refund->amount->compare($order->refundable()) >= 0) {
            foreach ($order->taxRows() as $row) {
                $row->giveBack($row->amount()->subtract($row->refunded())->atLeastZero());
            }

            return $taxLeft;
        }
        $share = $gross->isZero() ? Money::zero() : $tax->proportion($refund->amount, $gross)->rounded($decimals);
        $share = $share->compare($taxLeft) > 0 ? $taxLeft : $share;
        $left = $share;
        foreach ($covered as $index => [$line, , $lineTax]) {
            $part = $index === \count($covered) - 1 ? $left : ($tax->isZero() ? Money::zero() : $share->proportion($lineTax, $tax)->rounded($decimals));
            $left = $left->subtract($part);
            $line->refundTax($part);
        }

        return $share;
    }

    /**
     * What the customer paid for some of a line's items, and the tax inside it.
     *
     * @return array{Money, Money}
     */
    private static function lineShare(Order $order, OrderLine $line, int $quantity): array
    {
        $lineTax = $line->tax();
        $lineGross = $line->net()->add($order->pricesIncludeTax() ? Money::zero() : $lineTax);
        $part = Money::of((string) $quantity);
        $whole = Money::of((string) $line->quantity());

        return [$lineGross->proportion($part, $whole), $lineTax->proportion($part, $whole)];
    }

    /**
     * What a refund gave back of each rate: the order's tax rows now, less what they had given back before.
     *
     * @param list<Money> $before each row's refunded amount before the refund, as taxRows() lists them
     *
     * @return list<TaxLine>
     */
    public static function givenBack(Order $order, array $before): array
    {
        $shares = [];
        foreach ($order->taxRows() as $index => $row) {
            $given = $row->refunded()->subtract($before[$index] ?? Money::zero());
            if ($given->isZero()) {
                continue;
            }
            // By whom it is owed: a county's and a city's "Local tax" at 1 % each are two.
            $key = $row->jurisdiction()->code . '|' . $row->percent();
            $shares[$key] = new TaxLine($row->name(), $row->percent(), ($shares[$key]->amount ?? Money::zero())->add($given), $row->jurisdiction()->code);
        }

        return array_values($shares);
    }
}
