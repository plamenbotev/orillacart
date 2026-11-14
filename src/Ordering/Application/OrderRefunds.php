<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\RefundedView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Application\Dto\RestockItems;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRefund;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\TaxLine;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Money given back for an order, once it went: what the order keeps of it, the items back in stock, its history
 * and its events; and items that came back after.
 */
final readonly class OrderRefunds
{
    public function __construct(
        private OrderChanges $changes,
        private OrderRepository $orders,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {}

    /**
     * Whether the refund fits the order, before any money moves.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function checkRefund(int $id, RefundOrder $command): void
    {
        $this->changes->get($id)->checkRefund(self::orderRefund($command));
    }

    /**
     * Records money given back, once it went: puts the refunded items back in stock if asked, notes it in the
     * order's history and tells the customer. An order refunded in full becomes Refunded.
     *
     * @param string $how how the money went back, for the history: "through PayPal (refund 5TY…)"
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function refund(int $id, RefundOrder $command, string $how, ?int $userId, bool $tellCustomer = true, ?string $credit = null): RefundedView
    {
        return $this->changes->changing($id, fn(Order $order): RefundedView => $this->refunded($order, $command, $how, $userId, $tellCustomer, $credit));
    }

    private function refunded(Order $order, RefundOrder $command, string $how, ?int $userId, bool $tellCustomer, ?string $credit = null): RefundedView
    {
        $previous = $order->status();
        $refund = self::orderRefund($command);
        $share = $order->refund($refund);
        $this->changes->giveBack($share->taken, new StockNote(StockReason::Return, $order->id(), $userId));
        $taxes = array_map(static fn(TaxLine $tax): TaxLineView => new TaxLineView($tax->name, $tax->percent, (string) $tax->amount, $tax->code), $share->taxes);
        $text = \sprintf('Refunded %s %s %s.', $refund->amount, $order->currency(), $how);
        if ($refund->restock && $refund->quantities !== []) {
            $text .= ' The items went back in stock.';
        }
        $now = $this->clock->now();
        $order->addNote(trim($command->reason) === '' ? $text : $text . ' ' . trim($command->reason), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $now, $userId);
        if ($order->status() !== $previous) {
            $order->addNote(\sprintf('Status changed from %s to %s.', strtolower($previous->label()), strtolower($order->status()->label())), NoteAuthor::System, false, $now);
        }
        $this->orders->save($order);
        $full = $order->status() === OrderStatus::Refunded;
        $this->events->dispatch(new OrderRefunded($order->id(), (string) $refund->amount, $full, $refund->quantities, $tellCustomer, (string) $share->tax, array_map(static fn(TaxLineView $tax): array => ['name' => $tax->name, 'percent' => $tax->percent, 'amount' => $tax->amount], $taxes), $credit));
        if ($order->status() !== $previous) {
            // The refund's own email tells the customer.
            $this->events->dispatch(new OrderStatusChanged($order->id(), $order->status(), $previous, false, false));
        }

        return new RefundedView((string) $share->tax, $taxes);
    }

    /**
     * Items of a refunded order came back after its money went: they go back in stock, and the history says so.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not refunded, or items that hold no stock
     */
    public function restock(int $id, RestockItems $command, ?int $userId): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $quantities = [];
            foreach ($command->lines as $line) {
                $quantities[$line->line] = ($quantities[$line->line] ?? 0) + $line->quantity;
            }
            $this->changes->giveBack($order->restock($quantities), new StockNote(StockReason::Return, $order->id(), $userId));
            $order->addNote(\sprintf('%d items came back and went back in stock.', array_sum($quantities)), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderChanged($order->id()));

            return $this->changes->view($order, true);
        });
    }

    private static function orderRefund(RefundOrder $command): OrderRefund
    {
        $quantities = [];
        foreach ($command->lines as $line) {
            if ($line instanceof RefundLine) {
                $quantities[$line->line] = ($quantities[$line->line] ?? 0) + $line->quantity;
            }
        }

        return new OrderRefund(Money::of($command->amount), $quantities, $command->shipping, $command->restock);
    }
}
