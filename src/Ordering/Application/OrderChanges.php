<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\TakenItem;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What every change of an order goes through, whichever service makes it: one transaction with the order's row
 * locked, OrderPaid when the money came, and a status change's stock, downloads, history and event.
 */
final readonly class OrderChanges
{
    public function __construct(
        private OrderRepository $orders,
        private StockService $stock,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private OrderLinks $links,
        private Transactions $transactions,
    ) {}

    /**
     * Saves a status change: puts back the stock it freed, notes it in the order's history and announces it.
     *
     * @param list<TakenItem> $taken
     */
    public function changed(Order $order, OrderStatus $previous, array $taken, string $note, ?int $userId = null, bool $tellCustomer = true, bool $tellShop = true): void
    {
        if ($order->status() === $previous) {
            return;
        }
        $this->giveBack($taken, new StockNote(StockReason::Release, $order->id(), $userId));
        if ($order->grantDownloads(static fn(): string => bin2hex(random_bytes(32)), $this->clock->now())) {
            $order->addNote('The downloads were granted.', NoteAuthor::System, false, $this->clock->now());
        }
        $text = \sprintf('Status changed from %s to %s.', strtolower($previous->label()), strtolower($order->status()->label()));
        $order->addNote(trim($note) === '' ? $text : $text . ' ' . trim($note), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->events->dispatch(new OrderStatusChanged($order->id(), $order->status(), $previous, $tellCustomer, $tellShop));
    }

    /**
     * Runs a change of an order in one transaction, with the order's row locked.
     *
     * @template T
     *
     * @param \Closure(Order): T $work
     *
     * @param-immediately-invoked-callable $work
     *
     * @return T
     *
     * @throws OrderNotFound
     */
    public function changing(int $id, \Closure $work): mixed
    {
        return $this->transactions->run(function () use ($id, $work): mixed {
            $order = $this->orders->find($id, true) ?? throw new OrderNotFound();
            $unpaid = $order->paidAt() === null;
            $result = $work($order);
            // However the money came, this is where the order learnt of it.
            if ($unpaid && $order->paidAt() !== null) {
                $this->events->dispatch(new OrderPaid($order->id()));
            }

            return $result;
        });
    }

    /**
     * @param list<TakenItem> $taken
     */
    public function giveBack(array $taken, StockNote $note): void
    {
        if ($taken !== []) {
            $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken), $note);
        }
    }

    public function view(Order $order, bool $forShop = false, ?bool $shopWords = null): OrderView
    {
        return OrderView::fromOrder($order, $forShop, $this->links->downloadUrl(...), $shopWords, $this->clock->now());
    }

    public function get(int $id): Order
    {
        return $this->orders->find($id) ?? throw new OrderNotFound();
    }
}
