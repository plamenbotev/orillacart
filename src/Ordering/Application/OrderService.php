<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\LineDraft;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDraft;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\ShippingChoice;
use OrillaCart\Ordering\Domain\TakenItem;
use OrillaCart\Ordering\Domain\TaxLine;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Recording placed orders and moving them along as their payment ends. Closing an order that never got paid
 * puts its stock back.
 */
final readonly class OrderService
{
    /** How long a customer has to finish paying at a provider before the order is cancelled. */
    public const string PAYMENT_WINDOW = 'PT1H';

    public function __construct(
        private OrderRepository $orders,
        private StockService $stock,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {}

    public function place(NewOrder $command): OrderView
    {
        $order = new Order(new OrderDraft(
            bin2hex(random_bytes(16)),
            $command->customerId,
            $command->email,
            $command->billing->toAddress(),
            $command->shippingAddress?->toAddress(),
            array_map(static fn(NewOrderLine $line): LineDraft => new LineDraft(
                $line->productId,
                $line->variationId,
                $line->optionIds,
                $line->options,
                $line->name,
                $line->sku,
                $line->quantity,
                Money::of($line->unitPrice),
                Money::of($line->total),
                Money::of($line->tax),
                $line->ships,
                array_map(static fn(TakenStock $taken): TakenItem => new TakenItem($taken->item, $taken->stockroomId, $taken->quantity), $line->taken),
            ), $command->lines),
            $command->shipping === null ? null : new ShippingChoice(
                $command->shipping->rateId,
                $command->shipping->carrier,
                $command->shipping->rate,
                Money::of($command->shipping->price),
                Money::of($command->shipping->tax),
            ),
            array_map(static fn(TaxLineView $tax): TaxLine => new TaxLine($tax->name, $tax->percent, Money::of($tax->amount)), $command->taxes),
            Money::of($command->subtotal),
            Money::of($command->itemsTax),
            Money::of($command->total),
            $command->currency,
            $command->paymentGateway,
            $command->paymentName,
            $command->paymentNote,
            $command->note,
            $this->clock->now(),
        ));
        $this->orders->save($order);
        $this->events->dispatch(new OrderPlaced($order->id()));

        return OrderView::fromOrder($order);
    }

    /**
     * The receipt of an order, for whoever holds its key.
     *
     * @throws OrderNotFound
     */
    public function byKey(string $key): OrderView
    {
        return OrderView::fromOrder($this->orders->findByKey($key) ?? throw new OrderNotFound());
    }

    /**
     * @throws OrderNotFound
     */
    public function order(int $id): OrderView
    {
        return OrderView::fromOrder($this->get($id));
    }

    /**
     * The shop waits for the money: an offline payment, or one the provider holds for review.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that is no longer open
     */
    public function hold(int $id): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        if ($order->hold()) {
            $this->saved($order, $previous);
        }
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that was cancelled or failed before the money came
     */
    public function markPaid(int $id, ?string $transactionId): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        if ($order->markPaid($transactionId, $this->clock->now())) {
            $this->saved($order, $previous);
        }
    }

    /**
     * @throws OrderNotFound
     */
    public function fail(int $id): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        $this->close($order, $previous, $order->fail());
    }

    /**
     * @throws OrderNotFound
     */
    public function cancel(int $id): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        $this->close($order, $previous, $order->cancel());
    }

    /**
     * Cancels orders whose customer went to pay at a provider and neither paid nor came back within the
     * payment window, so their stock is sold again.
     *
     * @return int how many were cancelled
     */
    public function cancelAbandoned(): int
    {
        $orders = $this->orders->pendingPaymentSince($this->clock->now()->sub(new \DateInterval(self::PAYMENT_WINDOW)));
        foreach ($orders as $order) {
            $this->close($order, $order->status(), $order->cancel());
        }

        return \count($orders);
    }

    /**
     * @param list<TakenItem> $taken
     */
    private function close(Order $order, \OrillaCart\Ordering\Domain\OrderStatus $previous, array $taken): void
    {
        if ($order->status() === $previous) {
            return;
        }
        $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken));
        $this->saved($order, $previous);
    }

    private function saved(Order $order, \OrillaCart\Ordering\Domain\OrderStatus $previous): void
    {
        $this->orders->save($order);
        $this->events->dispatch(new OrderStatusChanged($order->id(), $order->status(), $previous));
    }

    private function get(int $id): Order
    {
        return $this->orders->find($id) ?? throw new OrderNotFound();
    }
}
