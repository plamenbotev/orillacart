<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Reports\Domain\GivenRefund;
use OrillaCart\Reports\Domain\OrderFacts;
use OrillaCart\Reports\Domain\SoldLine;
use OrillaCart\Reports\Domain\SoldOrder;
use OrillaCart\Reports\Domain\UsedCoupon;
use OrillaCart\Shared\Domain\Money;

/**
 * Keeps the reports' facts of orders that changed, a batch at a time, in one write: the reports read only these,
 * never the orders, so they stay quick however many orders the shop has. Placed, paid, edited, refunded or
 * deleted, an order is written again from scratch, so the facts never drift from it.
 */
final readonly class ProjectOrders implements JobHandler
{
    public const string TYPE = 'reports.order';


    public function __construct(
        private OrderService $orders,
        private Refunds $refunds,
        private ReportStore $store,
        private ShopCalendar $calendar,
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

    public function handle(array $jobs): array
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

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Order #%s', $uniqueKey ?? '?');
    }

    private function facts(OrderView $order): OrderFacts
    {
        $zone = $this->calendar->timezone();
        $day = new \DateTimeImmutable($order->placedAt)->setTimezone($zone)->setTime(0, 0);
        // Sales are paid orders, those refunded since included (refunds count on their own); an order paid on
        // delivery counts once its cash came.
        $counted = $order->paidAt !== null;
        $address = $order->shippingAddress ?? $order->billing;
        $items = array_sum(array_map(static fn(OrderLineView $line): int => $line->quantity, $order->lines));

        return new OrderFacts(
            new SoldOrder(
                $order->number,
                $day,
                new \DateTimeImmutable($order->placedAt)->setTimezone(new \DateTimeZone('UTC')),
                $counted,
                $order->status,
                $order->currency,
                $order->customerId !== null ? 'c' . $order->customerId : 'e' . mb_substr(mb_strtolower($order->email), 0, 200),
                $order->customerId,
                mb_substr(trim($order->billing->firstName . ' ' . $order->billing->lastName), 0, 200),
                $address->country,
                mb_substr($order->paymentName, 0, 100),
                mb_substr($order->shipping ?? '', 0, 255),
                $items,
                $order->subtotal,
                $order->discountTotal,
                $order->shippingTotal,
                (string) Money::of($order->itemsTax)->add(Money::of($order->shippingTax)),
                $order->total,
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
                (string) Money::of($line->total)->subtract(Money::of($line->discount)),
            ), $order->lines),
            array_map(static fn(OrderDiscountView $discount): UsedCoupon => new UsedCoupon(
                $order->number,
                $day,
                $counted,
                $order->currency,
                // Automatic discounts have no code to show: their description names them.
                mb_substr($discount->automatic ? $discount->description : $discount->code, 0, 40),
                $discount->amount,
            ), $order->discounts),
            array_values(array_map(static fn(RefundView $refund): GivenRefund => new GivenRefund(
                $refund->id,
                $order->number,
                new \DateTimeImmutable($refund->settledAt ?? $refund->createdAt)->setTimezone($zone)->setTime(0, 0),
                $refund->currency,
                $refund->amount,
            ), array_filter($this->refunds->forOrder($order->number)->refunds, static fn(RefundView $refund): bool => $refund->status === 'done'))),
        );
    }
}
