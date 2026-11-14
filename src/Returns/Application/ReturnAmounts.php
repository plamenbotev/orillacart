<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Coupons\Application\EarnedItems;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Returns\Domain\ReturnRepository;
use OrillaCart\Returns\Domain\ReturnStatus;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\FreeItemsOnReturn;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;

/**
 * What a return is worth: each item's share of what was paid, after the coupons and the items given with it; the
 * given items it takes back with it, or their price; and the coupons it takes back. Showing a return and refunding
 * it count the same way.
 */
final readonly class ReturnAmounts
{
    public function __construct(
        private ReturnRepository $returns,
        private Refunds $refunds,
        private SettingsService $settings,
        private EarnedItems $earned,
    ) {}

    /**
     * What the customer paid for these items: each line's total less its discount, with its tax unless the prices
     * include it, for the share of its items going back; in the currency's decimals. The return that takes back the
     * last of a line's items gets what is left of what was paid for it, so the returns of a line add up to it:
     * three returns of a 20.00 line come to 20.00, not 20.01.
     *
     * @param array<int, int> $items quantities by line position
     */
    public function amount(OrderView $order, array $items, ?int $returnId = null): Money
    {
        return $this->priced($order, $items, $returnId)[0];
    }

    /**
     * What these items refund (amount()), what of it the coupons whose least order what is kept no longer meets
     * keep back (less than nothing for what earlier returns kept back and these give again), and what they give
     * back of each line's worth before that, by line position.
     *
     * Each line's part is in the currency's decimals. The items that take the last of a line get what is left of
     * its worth once the earlier returns took what they gave back of it, items refunded without a return their
     * share (and what such a refund gave beyond it, givenBeyondWorth()), and the returns still to be refunded
     * theirs; the items before the last never get more than that. A line's returns add up to it, whatever the
     * coupons kept back on the way, and its refunds never to more than it cost.
     *
     * @param array<int, int> $items quantities by line position
     *
     * @return array{Money, Money, array<int, Money>}
     */
    public function priced(OrderView $order, array $items, ?int $returnId = null): array
    {
        // The items other returns are to refund, and what those refunded or refunding gave back of each line.
        $pending = [];
        $given = [];
        $givenQuantity = [];
        $ofReturns = [];
        foreach ($this->returns->forOrder($order->number) as $other) {
            if ($other->id() === $returnId) {
                continue;
            }
            if ($other->refundId() === null && $other->status() !== ReturnStatus::Refunded) {
                if ($other->status()->holdsItems()) {
                    foreach ($other->items() as $position => $quantity) {
                        $pending[$position] = ($pending[$position] ?? 0) + $quantity;
                    }
                }
                continue;
            }
            $values = $other->lineValues();
            if ($values === null) {
                // Recorded with no worths: its items count as refunded without a return.
                continue;
            }
            if ($other->refundId() !== null) {
                $ofReturns[] = $other->refundId();
            }
            foreach ($other->items() as $position => $quantity) {
                $givenQuantity[$position] = ($givenQuantity[$position] ?? 0) + $quantity;
                $given[$position] = ($given[$position] ?? Money::zero())->add($values[$position] ?? Money::zero());
            }
        }
        $mine = $returnId === null ? null : $this->returns->find($returnId);
        $refundId = $mine?->refundId();
        if ($mine !== null && $refundId === null && $mine->status() === ReturnStatus::Refunded) {
            // Its items cost nothing, and came back with no money.
            return [Money::zero(), Money::zero(), $mine->lineValues() ?? []];
        }
        if ($mine !== null && $refundId !== null) {
            // Refunded, or being refunded: what it gave back.
            $refund = array_find($this->refunds->forOrder($order->number)->refunds, static fn(RefundView $refund): bool => $refund->id === $refundId);
            if ($refund !== null) {
                return [Money::of($refund->amount), $mine->couponsTaken(), $mine->lineValues() ?? []];
            }
        }
        $refunding = $refundId !== null;
        $decimals = new Currency($order->currency)->decimals();
        $amount = Money::zero();
        $policy = $this->settings->current()->freeItemsOnReturn;
        // The items of a deal that gave free ones refund their share of what its items cost together.
        $shares = $policy === FreeItemsOnReturn::Spread->value ? $this->shares($order) : [];
        if ($policy === FreeItemsOnReturn::Charged->value) {
            // A free item what is kept no longer earns is the customer's at its price: kept from what goes back.
            foreach ($this->unearned($order, $items, $returnId) as $position => $units) {
                $line = $order->lines[$position];
                $percent = array_reduce($line->taxes, static fn(Money $sum, LineTaxView $tax): Money => $sum->add(Money::of($tax->percent)), Money::zero());
                $price = Money::of($line->unitPrice)->times($units);
                $amount = $amount->subtract($order->pricesIncludeTax ? $price : $price->add($price->percent((string) $percent)));
            }
        }
        $worth = static function (int $position, int $units) use ($order, $shares, $decimals): ?Money {
            $line = Returns::lineAt($order, $position);
            if ($line === null || $line->quantity < 1) {
                return null;
            }

            return ($shares[$position] ?? self::paid($order, $line))->proportion(Money::of((string) $units), Money::of((string) $line->quantity))->rounded($decimals);
        };
        $beyond = null;
        $values = [];
        foreach ($items as $position => $quantity) {
            $line = Returns::lineAt($order, $position);
            $whole = $line === null ? null : $worth($position, $line->quantity);
            if ($line === null || $whole === null) {
                continue;
            }
            $others = $pending[$position] ?? 0;
            $returned = $givenQuantity[$position] ?? 0;
            $outside = max(0, $line->refundedQuantity - $returned);
            if ($refunding) {
                $values[$position] = $worth($position, $quantity) ?? Money::zero();
            } else {
                // What is left of the line once the refunds made and those to come took theirs. The last of its
                // items get all of that; the others their share, and never more than it.
                $left = $whole->subtract($given[$position] ?? Money::zero())->subtract($worth($position, $others) ?? Money::zero());
                if ($outside > 0) {
                    $beyond ??= $this->givenBeyondWorth($order, $ofReturns, $worth, $decimals);
                    $left = $left->subtract($worth($position, $outside) ?? Money::zero())->subtract($beyond[$position] ?? Money::zero());
                }
                $left = $left->atLeastZero();
                $share = $worth($position, $quantity) ?? Money::zero();
                $values[$position] = $outside + $returned + $others + $quantity >= $line->quantity || $share->compare($left) > 0 ? $left : $share;
            }
            $amount = $amount->add($values[$position]);
        }

        $amount = ($amount->isNegative() ? Money::zero() : $amount)->rounded($decimals);
        // A coupon what is kept no longer meets (its least order) takes back what it took off the items kept,
        // less what the refunds of earlier returns took back of it already: when the rest goes back, it is given
        // again. Never more than these items refund; what that leaves is taken by the next.
        if ($refunding && $mine !== null) {
            $taken = $mine->couponsTaken();
        } else {
            $before = Money::zero();
            foreach ($this->returns->forOrder($order->number) as $other) {
                if ($other->id() !== $returnId && $other->refundId() !== null) {
                    $before = $before->add($other->couponsTaken());
                }
            }
            $gone = array_map(static fn(OrderLineView $line, int $position): int => $line->refundedQuantity + ($items[$position] ?? 0), $order->lines, array_keys($order->lines));
            $taken = $this->couponsTakenBack($order, $gone)->subtract($before)->rounded($decimals);
            $taken = $taken->compare($amount) > 0 ? $amount : $taken;
        }

        return [$amount->subtract($taken)->atLeastZero(), $taken, $values];
    }

    /**
     * What the refunds made without a return gave back beyond their items' worth, by line position: of two items
     * that cost 100, one refunded by hand with 80 leaves 20 for the other to give back, not its half. A refund's
     * money counts for its items, and its shipping, by what they are worth; one that names no item is no line's,
     * and one that failed gave nothing.
     *
     * @param list<int>                  $ofReturns the refunds of the returns whose worths are counted themselves
     * @param \Closure(int, int): ?Money $worth     what so many items of the line at a position are worth
     *
     * @return array<int, Money>
     */
    private function givenBeyondWorth(OrderView $order, array $ofReturns, \Closure $worth, int $decimals): array
    {
        $shipping = Money::of($order->shippingTotal)->add($order->pricesIncludeTax ? Money::zero() : Money::of($order->shippingTax));
        $beyond = [];
        foreach ($this->refunds->forOrder($order->number)->refunds as $refund) {
            if ($refund->status === 'failed' || $refund->lines === [] || \in_array($refund->id, $ofReturns, true)) {
                continue;
            }
            $worths = [];
            $all = $refund->shipping ? $shipping : Money::zero();
            foreach ($refund->lines as $line) {
                $part = $worth($line['line'], $line['quantity']);
                if ($part !== null) {
                    $worths[$line['line']] = ($worths[$line['line']] ?? Money::zero())->add($part);
                    $all = $all->add($part);
                }
            }
            $over = Money::of($refund->amount)->subtract($all);
            if ($all->isZero() || $over->compare(Money::zero()) <= 0) {
                continue;
            }
            foreach ($worths as $position => $part) {
                $beyond[$position] = ($beyond[$position] ?? Money::zero())->add($over->proportion($part, $all)->rounded($decimals));
            }
        }

        return $beyond;
    }

    /**
     * What the coupons whose least order what is kept no longer meets took off the items kept, with its tax unless
     * the prices include it: the kept items' share of what each took off, by what their lines were discounted.
     *
     * @param list<int> $gone the items of each line refunded or going back, by line position
     */
    private function couponsTakenBack(OrderView $order, array $gone): Money
    {
        $kept = [];
        $left = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            $left[$position] = max(0, $line->quantity - ($gone[$position] ?? 0));
            if ($line->givenBy === null && $line->addOn === null) {
                $kept[] = ['product_id' => $line->productId, 'quantity' => $left[$position], 'total' => (string) Money::of($line->total)->proportion(Money::of((string) $left[$position]), Money::of((string) max(1, $line->quantity)))];
            }
        }
        $taken = Money::zero();
        foreach ($order->discounts as $discount) {
            $off = Money::of($discount->amount);
            if ($off->isZero() || $this->earned->stillMet($discount->code, $kept, $order->currency, $order->exchangeRate ?? '1') !== false) {
                continue;
            }
            $all = Money::zero();
            $ofKept = Money::zero();
            foreach ($order->lines as $position => $line) {
                if ($line->givenBy !== null || $line->addOn !== null || !$this->earned->appliesTo($discount->code, $line->productId)) {
                    continue;
                }
                $lineDiscount = Money::of($line->discount);
                $all = $all->add($lineDiscount);
                $share = $lineDiscount->proportion(Money::of((string) $left[$position]), Money::of((string) max(1, $line->quantity)));
                $percent = array_reduce($line->taxes, static fn(Money $sum, LineTaxView $tax): Money => $sum->add(Money::of($tax->percent)), Money::zero());
                $ofKept = $ofKept->add($order->pricesIncludeTax ? $share : $share->add($share->percent((string) $percent)));
            }
            if (!$all->isZero()) {
                $taken = $taken->add($ofKept->proportion($off, $all));
            }
        }

        return $taken;
    }

    /** What the customer paid for the line: its total less its discount, with its tax unless the prices include it. */
    private static function paid(OrderView $order, OrderLineView $line): Money
    {
        return Money::of($line->total)->subtract(Money::of($line->discount))->add($order->pricesIncludeTax ? Money::zero() : Money::of($line->tax));
    }

    /**
     * Each line's share of a deal that gave free items, by line position: what the deal's items (those it gave, and
     * those it applies to) cost together, after every coupon, shared out in the currency's decimals so the shares
     * come to that cost exactly. A bought line weighs what it paid, a given one its price: a free 2-euro keychain of
     * a 100-euro lamp deal refunds about 2 of it, not half, and a shade another coupon halved weighs its half.
     * Deals that share a line are shared out together, so the line's money is never counted twice.
     *
     * @return array<int, Money>
     */
    private function shares(OrderView $order): array
    {
        $groups = [];
        foreach ($order->lines as $line) {
            if ($line->givenBy === null) {
                continue;
            }
            $code = $line->givenBy['code'];
            $members = [];
            foreach ($order->lines as $position => $other) {
                \assert($other instanceof OrderLineView);
                if (($other->givenBy['code'] ?? null) === $code || ($other->givenBy === null && $other->addOn === null && $this->earned->appliesTo($code, $other->productId))) {
                    $members[$position] = true;
                }
            }
            // A deal joins the groups it shares a line with.
            foreach ($groups as $index => $group) {
                if (array_intersect_key($group, $members) !== []) {
                    $members += $group;
                    unset($groups[$index]);
                }
            }
            $groups[] = $members;
        }
        $decimals = new Currency($order->currency)->decimals();
        $shares = [];
        foreach ($groups as $group) {
            $lines = array_intersect_key($order->lines, $group);
            $cost = array_reduce($lines, static fn(Money $sum, OrderLineView $line): Money => $sum->add(self::paid($order, $line)), Money::zero())->rounded($decimals);
            $weights = array_map(static fn(OrderLineView $line): Money => $line->givenBy === null ? self::paid($order, $line) : self::priceOf($order, $line), $lines);
            $whole = array_reduce($weights, static fn(Money $sum, Money $weight): Money => $sum->add($weight), Money::zero());
            if ($whole->isZero()) {
                $weights = array_map(static fn(OrderLineView $line): Money => Money::of((string) $line->quantity), $lines);
                $whole = Money::of((string) array_sum(array_map(static fn(OrderLineView $line): int => $line->quantity, $lines)));
            }
            if ($whole->isZero()) {
                continue;
            }
            // The heaviest line takes what rounding leaves, as it has the most room for it.
            $heaviest = null;
            foreach ($weights as $position => $weight) {
                if ($heaviest === null || $weight->compare($weights[$heaviest]) > 0) {
                    $heaviest = (int) $position;
                }
            }
            $left = $cost;
            foreach ($weights as $position => $weight) {
                if ((int) $position !== $heaviest) {
                    $share = $cost->proportion($weight, $whole)->rounded($decimals);
                    $shares[(int) $position] = $share;
                    $left = $left->subtract($share);
                }
            }
            if ($heaviest !== null) {
                $shares[$heaviest] = $left;
            }
        }

        return $shares;
    }

    /** What a line's items cost before any discount: its total, with its tax unless the prices include it. */
    private static function priceOf(OrderView $order, OrderLineView $line): Money
    {
        $total = Money::of($line->total);
        if ($order->pricesIncludeTax) {
            return $total;
        }
        $percent = array_reduce($line->taxes, static fn(Money $sum, LineTaxView $tax): Money => $sum->add(Money::of($tax->percent)), Money::zero());

        return $total->add($total->percent((string) $percent));
    }

    /**
     * The free items the customer would keep that what they keep no longer earns, once these items go back with
     * those already refunded or held by other returns: by the given line's position. None for a coupon gone since.
     *
     * @param array<int, int> $items quantities by line position, going back now
     * @param int|null        $returnId the return they are, left out of the others
     *
     * @return array<int, int>
     */
    public function unearned(OrderView $order, array $items, ?int $returnId = null): array
    {
        $back = $items;
        foreach ($this->returns->forOrder($order->number) as $other) {
            if ($other->id() !== $returnId && $other->status()->holdsItems()) {
                foreach ($other->items() as $position => $quantity) {
                    $back[$position] = ($back[$position] ?? 0) + $quantity;
                }
            }
        }
        $kept = [];
        $given = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            $left = max(0, $line->quantity - $line->refundedQuantity - ($back[$position] ?? 0));
            if ($line->givenBy !== null) {
                $given[$line->givenBy['code']][$position] = $left;
            } else {
                $kept[] = ['product_id' => $line->productId, 'quantity' => $left, 'total' => (string) Money::of($line->total)->proportion(Money::of((string) $left), Money::of((string) max(1, $line->quantity)))];
            }
        }
        $unearned = [];
        foreach ($given as $code => $lines) {
            $earned = $this->earned->earned((string) $code, $kept, $order->currency, $order->exchangeRate ?? '1');
            if ($earned === null) {
                continue;
            }
            // The last given go first.
            $over = array_sum($lines) - $earned;
            foreach (array_reverse($lines, true) as $position => $left) {
                $units = min($over, $left);
                if ($units > 0) {
                    $unearned[$position] = $units;
                    $over -= $units;
                }
            }
        }
        ksort($unearned);

        return $unearned;
    }
}
