<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Extension\Order\OrderLine;
use OrillaCart\Extension\Order\Orders;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\NewOrderFee;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Domain\MarkedOrder;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Shared\Domain\Money;

/**
 * Orders as the extension API shows them to add-ons, and the marks add-ons put on them for their filters.
 */
final readonly class OrderSummaries implements Orders
{
    public function __construct(
        private OrderService $orders,
        private OrderRepository $records,
    ) {}

    public function mark(int $number, string $filterId): bool
    {
        if (preg_match(MarkedOrder::MARK, $filterId) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an order filter\'s id: lowercase letters, digits and underscores, up to 40.', $filterId));
        }

        return $this->records->mark($number, $filterId);
    }

    public function unmark(int $number, string $filterId): void
    {
        $this->records->unmark($number, $filterId);
    }

    public function isMarked(int $number, string $filterId): bool
    {
        return $this->records->isMarked($number, $filterId);
    }

    public function find(int $number): ?OrderSummary
    {
        try {
            return self::summary($this->orders->order($number));
        } catch (OrderNotFound) {
            return null;
        }
    }

    /**
     * Every order the search finds, in its order, a batch at a time: each batch's orders are read in a few queries
     * and handed over together, and the next batch starts after the last of them, so an order placed or deleted
     * meanwhile neither repeats one nor skips one. Forget what was loaded between batches, as $write may.
     *
     * @param \Closure(list<OrderSummary>): void $write
     */
    public function each(OrderSearch $search, int $batch, \Closure $write): void
    {
        $after = null;
        do {
            $orders = $this->records->searchAfter($search, $after, $batch);
            if ($orders === []) {
                return;
            }
            // A summary shows no downloads: their links are not made.
            $write(array_map(static fn(Order $order): OrderSummary => self::summary(OrderView::fromOrder($order, true)), $orders));
            $after = $orders[\count($orders) - 1];
        } while (\count($orders) === $batch);
    }

    public static function summary(OrderView $order): OrderSummary
    {
        return new OrderSummary(
            $order->number,
            $order->status,
            new \DateTimeImmutable($order->placedAt),
            $order->paidAt === null ? null : new \DateTimeImmutable($order->paidAt),
            $order->email,
            $order->customerId,
            self::address($order->billing),
            $order->shippingAddress === null ? null : self::address($order->shippingAddress),
            array_map(static fn(OrderLineView $line): OrderLine => new OrderLine($line->productId, $line->variationId, $line->sku, $line->name, $line->quantity, $line->unitPrice, $line->total, $line->tax, array_map(static fn(array $answer): array => ['label' => $answer['label'], 'answer' => $answer['answer'], 'price' => $answer['price']], $line->personalisation), $line->type, $line->addOn['type'] ?? null), $order->lines),
            $order->subtotal,
            $order->discountTotal,
            $order->shippingTotal,
            (string) Money::of($order->itemsTax)->add(Money::of($order->shippingTax))->add(Money::of($order->feesTax)),
            $order->total,
            $order->refundedTotal,
            $order->currency,
            $order->paymentGateway,
            $order->transactionId,
            $order->shipping,
            $order->note,
            array_column(array_map(static fn(OrderFieldView $field): array => [$field->id, $field->answer], $order->fields), 1, 0),
            array_column(array_map(static fn(NewOrderFee $fee): array => [$fee->label, $fee->amount], $order->fees), 1, 0),
        );
    }

    private static function address(AddressData $address): OrderAddress
    {
        return new OrderAddress($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->state, $address->phone);
    }
}
