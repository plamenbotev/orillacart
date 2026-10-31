<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Jobs\Application\KeepsFailures;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Reports\Domain\CollectedTax;
use OrillaCart\Reports\Domain\GivenRefund;
use OrillaCart\Reports\Domain\OrderFacts;
use OrillaCart\Reports\Domain\SoldLine;
use OrillaCart\Reports\Domain\SoldOrder;
use OrillaCart\Reports\Domain\UsedCoupon;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\ReportDay;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Security\Signatures;

/**
 * Keeps the reports' facts of orders that changed, a batch at a time, in one write: the reports read only these,
 * never the orders, so they stay quick however many orders the shop has. Placed, paid, edited, refunded or
 * deleted, an order is written again from scratch, so the facts never drift from it. Every job of the batch is
 * reported once the write is in.
 */
final readonly class ProjectOrders implements KeepsFailures
{
    public const string TYPE = 'reports.order';
    private const string GUEST_PURPOSE = 'report-guest';

    public function __construct(
        private OrderService $orders,
        private Refunds $refunds,
        private ReportStore $store,
        private ShopCalendar $calendar,
        private Signatures $signatures,
        private SettingsService $settings,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'reports';
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return 30 * 2 ** ($attempt - 1);
    }

    /** One batch: its orders read and one write for all their facts, as the write is what takes the batch. */
    public function lockSeconds(): int
    {
        return 300;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        $ids = array_values(array_unique(array_map(static fn(QueuedJob $job): int => (int) $job->uniqueKey, $jobs)));
        $facts = [];
        foreach ($ids as $id) {
            try {
                $facts[] = $this->facts($this->orders->order($id));
            } catch (OrderNotFound) {
                // Deleted: what was kept of it goes.
            }
        }
        $this->store->replace($ids, $facts);
        // The facts are written: every job of the batch is done now.
        foreach ($jobs as $job) {
            $progress->done($job);
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Order #%s', $uniqueKey ?? '?');
    }

    private function facts(OrderView $order): OrderFacts
    {
        $zone = $this->calendar->timezone();
        // Sales are paid orders, those refunded since included (refunds count on their own); an order paid on
        // delivery counts once its cash came.
        $counted = $order->paidAt !== null;
        // Counted on the day its money came, as filed periods want, or on the day it was placed: the shop's choice.
        $countedAt = $counted && $this->settings->current()->reportDay === ReportDay::Paid->value ? (string) $order->paidAt : $order->placedAt;
        $day = new \DateTimeImmutable($countedAt)->setTimezone($zone)->setTime(0, 0);
        $address = $order->shippingAddress ?? $order->billing;
        $items = array_sum(array_map(static fn(OrderLineView $line): int => $line->quantity, $order->lines));
        // Facts are before tax: amounts that include it give it up here.
        $net = static fn(string $amount, string $tax): string => $order->pricesIncludeTax ? (string) Money::of($amount)->subtract(Money::of($tax)) : $amount;
        // The items' prices and their discounts before tax alike: with prices that include it, the items' tax is
        // shared between them as the price after the discount is between its net and its tax.
        [$gross, $discount, $beforeTax] = self::itemsBeforeTax($order);
        $refunds = $counted ? array_values(array_filter($this->refunds->forOrder($order->number)->refunds, static fn(RefundView $refund): bool => $refund->status === 'done')) : [];
        $refundDay = static fn(RefundView $refund): \DateTimeImmutable => new \DateTimeImmutable($refund->settledAt ?? $refund->createdAt)->setTimezone($zone)->setTime(0, 0);

        return new OrderFacts(
            new SoldOrder(
                $order->number,
                $day,
                new \DateTimeImmutable($order->placedAt)->setTimezone(new \DateTimeZone('UTC')),
                $counted,
                $order->status,
                $order->currency,
                // An erased customer is nobody known again: each such order is a customer of its own.
                match (true) {
                    $order->customerId !== null => 'c' . $order->customerId,
                    $order->anonymised => 'o' . $order->number,
                    // A guest by a keyed hash of the email: told apart, not named. A new site secret
                    // tells them apart anew from the next rebuild.
                    default => 'e' . $this->signatures->sign(self::GUEST_PURPOSE, mb_strtolower(trim($order->email))),
                },
                $order->customerId,
                mb_substr(trim($order->billing->firstName . ' ' . $order->billing->lastName), 0, 200),
                $address->country,
                mb_substr($order->paymentName, 0, 100),
                mb_substr($order->shipping ?? '', 0, 255),
                $items,
                $gross,
                $discount,
                $net($order->shippingTotal, $order->shippingTax),
                (string) Money::of($order->itemsTax)->add(Money::of($order->shippingTax))->add(Money::of($order->feesTax)),
                $order->total,
                $address->state === null || trim($address->state) === '' ? null : mb_strtoupper(mb_substr(trim($address->state), 0, 3)),
                $order->reverseCharge,
                // Kept only for an order in another currency than the shop's.
                $order->exchangeRate,
            ),
            array_map(static fn(OrderLineView $line): SoldLine => new SoldLine(
                $order->number,
                $day,
                $counted,
                $order->currency,
                $line->productId,
                $line->variationId,
                mb_substr($line->name, 0, 255),
                $line->quantity,
                $net((string) Money::of($line->total)->subtract(Money::of($line->discount)), $line->tax),
                self::refundedNet($line, $order->pricesIncludeTax),
                $line->givenBy !== null,
            ), $order->lines),
            array_map(static fn(OrderDiscountView $discount): UsedCoupon => new UsedCoupon(
                $order->number,
                $day,
                $counted,
                $order->currency,
                // Automatic discounts have no code to show: their description names them.
                mb_substr($discount->automatic ? $discount->description : $discount->code, 0, 40),
                $beforeTax($discount->amount),
            ), $order->discounts),
            // Refunds of an order that counts, each on its own day: the money given back with its tax, and the
            // items' share of it before tax, which is what net sales lose.
            array_map(static fn(RefundView $refund): GivenRefund => new GivenRefund(
                $refund->id,
                $order->number,
                $refundDay($refund),
                $refund->currency,
                $refund->amount,
                $refund->tax,
                (string) self::itemsShare($order, $refund),
            ), $refunds),
            [...self::collected($order, $day, $counted), ...self::refundedTaxes($order, $refunds, $refundDay)],
        );
    }

    /**
     * The items' prices, their discounts and a way to take the tax off an amount of them, all before tax: with
     * prices that include it, each by the share the items' price after discounts is of its net.
     *
     * @return array{string, string, \Closure(string): string}
     */
    private static function itemsBeforeTax(OrderView $order): array
    {
        if (!$order->pricesIncludeTax) {
            return [$order->subtotal, $order->discountTotal, static fn(string $amount): string => $amount];
        }
        $afterDiscount = Money::of($order->subtotal)->subtract(Money::of($order->discountTotal));
        $netAfter = $afterDiscount->subtract(Money::of($order->itemsTax));
        if ($afterDiscount->isZero() || $afterDiscount->isNegative()) {
            // Discounted to nothing: no tax was charged to share, and the items brought nothing.
            $gross = Money::of($order->subtotal)->subtract(Money::of($order->itemsTax));

            return [(string) $gross, (string) $gross, static fn(string $amount): string => $amount];
        }
        $beforeTax = static fn(string $amount): string => (string) Money::of($amount)->proportion($netAfter, $afterDiscount);
        $gross = Money::of($beforeTax($order->subtotal));

        return [(string) $gross, (string) $gross->subtract($netAfter), $beforeTax];
    }

    /**
     * The lines a refund covers, as the order shared its money among them: the refunded items by quantity and
     * the shipping, or every line when it names neither.
     *
     * @return list<array{OrderLineView, Money, Money}> each line, the money the refund gave back on it, and what the whole line cost
     */
    private static function covered(OrderView $order, RefundView $refund): array
    {
        $byPosition = [];
        foreach ($order->lines as $line) {
            $byPosition[$line->position] = $line;
        }
        $covered = [];
        foreach ($refund->lines as $refunded) {
            $line = $byPosition[$refunded['line']] ?? null;
            if ($line !== null && $line->quantity > 0) {
                $covered[] = [$line, $refunded['quantity']];
            }
        }
        if ($refund->shipping) {
            foreach ($order->charges as $charge) {
                if ($charge->kind === 'shipping') {
                    $covered[] = [$charge, max(1, $charge->quantity)];
                }
            }
        }
        if ($covered === []) {
            foreach ([...$order->lines, ...$order->charges] as $line) {
                $covered[] = [$line, max(1, $line->quantity)];
            }
        }
        $gross = Money::zero();
        $parts = [];
        foreach ($covered as [$line, $quantity]) {
            $whole = self::lineGross($line, $order->pricesIncludeTax);
            $part = $whole->proportion(Money::of((string) $quantity), Money::of((string) max(1, $line->quantity)));
            $gross = $gross->add($part);
            $parts[] = [$line, $part, $whole];
        }
        $amount = Money::of($refund->amount);

        return array_values(array_filter(
            array_map(static fn(array $part): array => [$part[0], $amount->proportion($part[1], $gross), $part[2]], $parts),
            static fn(array $part): bool => !$part[2]->isZero(),
        ));
    }

    /** What the customer paid for a whole line, tax included. */
    private static function lineGross(OrderLineView $line, bool $pricesIncludeTax): Money
    {
        $net = Money::of($line->total)->subtract(Money::of($line->discount));

        return $pricesIncludeTax ? $net : $net->add(Money::of($line->tax));
    }

    /** What a whole line brought before tax. */
    private static function lineNet(OrderLineView $line, bool $pricesIncludeTax): Money
    {
        $net = Money::of($line->total)->subtract(Money::of($line->discount));

        return $pricesIncludeTax ? $net->subtract(Money::of($line->tax)) : $net;
    }

    /** The items' share of a refund before tax: what it gave back of them, less the tax in it. */
    private static function itemsShare(OrderView $order, RefundView $refund): Money
    {
        $share = Money::zero();
        foreach (self::covered($order, $refund) as [$line, $given, $whole]) {
            if ($line->kind === 'item') {
                $share = $share->add(self::lineNet($line, $order->pricesIncludeTax)->proportion($given, $whole));
            }
        }

        return $share;
    }

    /**
     * The tax each line was charged, on the day the order counts; and for a sale under the reverse charge, its base
     * at nothing, for the intra-EU supplies box and the EC Sales List. What refunds gave back counts on their own day.
     *
     * @return list<CollectedTax>
     */
    private static function collected(OrderView $order, \DateTimeImmutable $day, bool $counted): array
    {
        $rows = [];
        foreach ([...$order->lines, ...$order->charges] as $line) {
            foreach ($line->taxes as $tax) {
                $rows[] = new CollectedTax($order->number, $day, $counted, $order->currency, $tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction, $tax->percent, $line->kind, $tax->taxable, $tax->amount, '0', $tax->exempt);
            }
            if ($order->reverseCharge && $line->taxes === []) {
                $rows[] = self::reverseCharged($order, $day, $counted, $line, (string) Money::of($line->total)->subtract(Money::of($line->discount)));
            }
        }

        return $rows;
    }

    private static function reverseCharged(OrderView $order, \DateTimeImmutable $day, bool $counted, OrderLineView $line, string $base): CollectedTax
    {
        return new CollectedTax($order->number, $day, $counted, $order->currency, $order->billing->country, null, 'country', $order->billing->country . '-RC', 'Reverse charge (' . $order->billing->country . ')', '0', $line->kind, $base, '0', '0', false);
    }

    /**
     * What each refund gave back, by the rate it was charged at and on the day the refund was made: the tax, and
     * the base it was charged on lowered by the refunded lines' share, a reverse-charge sale's base too. A refund in
     * the next quarter lowers that quarter's, never a period filed already.
     *
     * @param list<RefundView>                            $refunds of an order that counts
     * @param \Closure(RefundView): \DateTimeImmutable    $refundDay
     *
     * @return list<CollectedTax>
     */
    private static function refundedTaxes(OrderView $order, array $refunds, \Closure $refundDay): array
    {
        $charged = [];
        foreach ([...$order->lines, ...$order->charges] as $line) {
            foreach ($line->taxes as $tax) {
                $charged[$tax->code . '|' . self::percent($tax->percent)] ??= [$tax, $line->kind];
                $charged[$tax->name . '|' . self::percent($tax->percent)] ??= [$tax, $line->kind];
            }
        }
        $rows = [];
        foreach ($refunds as $refund) {
            $day = $refundDay($refund);
            foreach ($refund->taxes as $given) {
                // By whom it is owed; a refund from before codes were kept, by its name.
                $code = $given['code'] ?? '';
                [$tax, $kind] = $charged[($code !== '' ? $code : $given['name']) . '|' . self::percent($given['percent'])] ?? [null, 'item'];
                if ($tax !== null) {
                    $rows[] = new CollectedTax($order->number, $day, true, $order->currency, $tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction, $tax->percent, $kind, '0', '0', $given['amount'], $tax->exempt);
                }
            }
            foreach (self::covered($order, $refund) as [$line, $given, $whole]) {
                foreach ($line->taxes as $tax) {
                    $base = Money::of($tax->taxable)->proportion($given, $whole);
                    if (!$base->isZero()) {
                        $rows[] = new CollectedTax($order->number, $day, true, $order->currency, $tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction, $tax->percent, $line->kind, (string) Money::zero()->subtract($base), '0', '0', $tax->exempt);
                    }
                }
                if ($order->reverseCharge && $line->taxes === []) {
                    $base = self::lineNet($line, $order->pricesIncludeTax)->proportion($given, $whole);
                    if (!$base->isZero()) {
                        $rows[] = self::reverseCharged($order, $day, true, $line, (string) Money::zero()->subtract($base));
                    }
                }
            }
        }

        return $rows;
    }

    /** "19.00" and "19" alike; "10" stays 10. */
    private static function percent(string $percent): string
    {
        return str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent;
    }

    /** A line's share of the refunds before tax: its refunded money less the tax in it, as the line's net is of its gross. */
    private static function refundedNet(OrderLineView $line, bool $pricesIncludeTax): string
    {
        $refunded = Money::of($line->refundedAmount);
        $net = Money::of($line->total)->subtract(Money::of($line->discount));
        if ($pricesIncludeTax) {
            $net = $net->subtract(Money::of($line->tax));
        }
        $gross = $net->add(Money::of($line->tax));

        return (string) $refunded->proportion($net, $gross);
    }
}
