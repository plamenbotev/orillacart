<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Shared\Domain\Money;

/**
 * Sharing an amount out by weights, to the cent, so the parts add up to it: coupons over the items they cover,
 * a change over the lines, a bundle's price over its items, shipping's tax over the items' rates.
 *
 * @phpstan-import-type PricedLine from OrderPricing
 */
final class PriceShares
{
    /**
     * An amount, above or below zero, shared over weights in the currency's decimals: rounded once, the cents
     * rounding leaves to the shares that lost the most, so the shares add up to it.
     *
     * @param list<Money> $weights
     *
     * @return list<Money>
     */
    public static function spread(Money $amount, array $weights, int $decimals): array
    {
        $negative = $amount->isNegative();
        $whole = ($negative ? Money::zero()->subtract($amount) : $amount)->rounded($decimals);
        $exact = self::shares($whole, $weights === [] ? [Money::of('1')] : $weights);
        $step = 10 ** (Money::DECIMALS - $decimals);
        $floors = array_map(static fn(Money $part): int => intdiv($part->tenThousandths, $step) * $step, $exact);
        $order = array_keys($exact);
        usort($order, static fn(int $a, int $b): int => [$exact[$b]->tenThousandths - $floors[$b], $a] <=> [$exact[$a]->tenThousandths - $floors[$a], $b]);
        for ($left = intdiv($whole->tenThousandths - array_sum($floors), $step), $i = 0; $left > 0 && $order !== []; --$left, ++$i) {
            $floors[$order[$i % \count($order)]] += $step;
        }

        return array_values(array_map(static fn(int $share): Money => Money::fromTenThousandths($negative ? -$share : $share), $floors));
    }

    /**
     * What each coupon takes off each item, in the currency's decimals: its whole amount rounded, shared over the
     * items by what it takes off each, the cents rounding leaves to the items that lost the most, so an order's
     * lines add up to it. No coupon takes more than the coupons before it left of an item: 10 % and then 100 % off
     * 9.95 take 1.00 and 8.95, not the 8.96 that 8.955 rounds to.
     *
     * @param list<Money> $totals each item's total, in the currency's decimals
     *
     * @return list<list<Money>> by coupon, then by item
     */
    public static function roundedCoupons(AppliedDiscounts $discounts, array $totals, int $decimals): array
    {
        $rounded = [];
        $step = 10 ** (Money::DECIMALS - $decimals);
        $left = array_map(static fn(Money $total): int => $total->tenThousandths, $totals);
        $items = \count($totals);
        foreach ($discounts->coupons as $coupon) {
            $exact = [];
            for ($index = 0; $index < $items; ++$index) {
                $exact[] = Money::of($coupon->lines[$index] ?? '0')->tenThousandths;
            }
            $whole = Money::fromTenThousandths(array_sum($exact))->rounded($decimals)->tenThousandths;
            $floors = array_map(static fn(int $part): int => intdiv($part, $step) * $step, $exact);
            $order = array_keys($exact);
            usort($order, static fn(int $a, int $b): int => [$exact[$b] - $floors[$b], $a] <=> [$exact[$a] - $floors[$a], $b]);
            for ($cents = intdiv($whole - array_sum($floors), $step), $i = 0; $cents > 0 && $order !== []; --$cents, ++$i) {
                $floors[$order[$i % \count($order)]] += $step;
            }
            foreach ($floors as $index => $floor) {
                $floors[$index] = $floor < $left[$index] ? $floor : $left[$index];
                $left[$index] -= $floors[$index];
            }
            $rounded[] = array_values(array_map(Money::fromTenThousandths(...), $floors));
        }

        return $rounded;
    }

    /**
     * An amount shared out in proportion to weights, exactly: what proportions leave over goes to the largest.
     * All of it to the first when the weights add up to nothing.
     *
     * @param non-empty-list<Money> $weights
     *
     * @return non-empty-list<Money> one share per weight, adding up to the amount
     */
    public static function shares(Money $amount, array $weights): array
    {
        $whole = array_reduce($weights, static fn(Money $sum, Money $weight): Money => $sum->add($weight), Money::zero());
        $shares = [];
        $left = $amount;
        $largest = 0;
        foreach ($weights as $index => $weight) {
            $share = $whole->isZero() ? ($index === 0 ? $amount : Money::zero()) : $amount->proportion($weight, $whole);
            $shares[] = $share;
            $left = $left->subtract($share);
            if ($weight->compare($weights[$largest]) > 0) {
                $largest = $index;
            }
        }
        $shares[$largest] = $shares[$largest]->add($left);

        return array_values($shares);
    }

    /**
     * The items' rates, for shipping taxed as the items it carries: each set of rates with what the items at it cost,
     * untaxed items too (their share of shipping is untaxed); the first item's group stands for the set's.
     *
     * @param list<list<array{total: Money, taxes: list<Tax>, group: ?int, tax_code: ?string, taxed: bool}>> $taxesOf
     *
     * @return non-empty-list<array{weight: Money, taxes: list<Tax>, group: ?int}>
     */
    public static function itemTaxShares(array $taxesOf): array
    {
        $sets = [];
        foreach ($taxesOf as $parts) {
            foreach ($parts as $part) {
                $key = implode('|', array_map(static fn(Tax $tax): string => $tax->key . ($tax->exempt ? '!' : ''), $part['taxes']));
                $sets[$key] ??= ['weight' => Money::zero(), 'taxes' => $part['taxes'], 'group' => $part['group']];
                $sets[$key]['weight'] = $sets[$key]['weight']->add($part['total']);
            }
        }

        return $sets === [] ? [['weight' => Money::of('1'), 'taxes' => [], 'group' => null]] : array_values($sets);
    }
}
