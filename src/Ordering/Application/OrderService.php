<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\ChangeDownloadAllowance;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\NewOrderItems;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderPageView;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\Dto\OrderSummaryView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\LineDownload;
use OrillaCart\Ordering\Domain\LineDraft;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDraft;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\OrderStatus;
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
        private OrderLinks $links,
    ) {}

    public function place(NewOrder $command): OrderView
    {
        $order = new Order(new OrderDraft(
            bin2hex(random_bytes(16)),
            $command->customerId,
            $command->email,
            $command->billing->toAddress(),
            $command->shippingAddress?->toAddress(),
            self::lineDrafts($command->lines),
            self::shippingChoice($command->shipping),
            self::taxLines($command->taxes),
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
        $order->addNote('Order placed.', NoteAuthor::System, false, $this->clock->now());
        $this->orders->save($order);
        $this->events->dispatch(new OrderPlaced($order->id()));

        return $this->view($order);
    }

    /**
     * The receipt of an order, for whoever holds its key.
     *
     * @throws OrderNotFound
     */
    public function byKey(string $key): OrderView
    {
        return $this->view($this->orders->findByKey($key) ?? throw new OrderNotFound());
    }

    /**
     * An order as the shop sees it, with all its notes.
     *
     * @throws OrderNotFound
     */
    public function order(int $id): OrderView
    {
        return $this->view($this->get($id), true);
    }

    /**
     * An order of a customer, as they see it; another customer's order is not found.
     *
     * @throws OrderNotFound
     */
    public function customerOrder(int $customerId, int $id): OrderView
    {
        $order = $this->get($id);
        if ($order->customerId() !== $customerId) {
            throw new OrderNotFound();
        }

        return $this->view($order);
    }

    public function orders(OrderQuery $query): OrderPageView
    {
        $search = $query->search === null || trim($query->search) === '' ? null : trim($query->search);
        [$orders, $total] = $this->orders->search(
            $query->status === null ? null : OrderStatus::from($query->status),
            $search,
            $query->customerId,
            ($query->page - 1) * $query->perPage,
            $query->perPage,
        );

        return new OrderPageView(array_map(OrderSummaryView::fromOrder(...), $orders), $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
    }

    /**
     * The shop waits for the money: an offline payment, or one the provider holds for review.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that is no longer open
     */
    public function hold(int $id, string $note = ''): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        if ($order->hold()) {
            $this->changed($order, $previous, [], $note);
        }
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that was cancelled or failed before the money came
     */
    public function markPaid(int $id, ?string $transactionId, string $note = ''): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        if ($order->markPaid($transactionId, $this->clock->now())) {
            $this->changed($order, $previous, [], $note);
        }
    }

    /**
     * @throws OrderNotFound
     */
    public function fail(int $id, string $note = ''): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        $this->changed($order, $previous, $order->fail(), $note);
    }

    /**
     * @throws OrderNotFound
     */
    public function cancel(int $id, string $note = ''): void
    {
        $order = $this->get($id);
        $previous = $order->status();
        $this->changed($order, $previous, $order->cancel(), $note);
    }

    /**
     * The shop moves an order along from its admin screen.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for a move the order's status does not allow
     */
    public function changeStatus(int $id, ChangeOrderStatus $command, ?int $userId): OrderView
    {
        $order = $this->get($id);
        $previous = $order->status();
        $taken = $order->moveTo(OrderStatus::from($command->status), $command->restock, $this->clock->now());
        $this->changed($order, $previous, $taken, $command->note, $userId);

        return $this->view($order, true);
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function changeContact(int $id, ChangeOrderContact $command, ?int $userId): OrderView
    {
        $order = $this->get($id);
        $order->changeContact($command->email, $command->billing->toAddress(), $command->shippingAddress?->toAddress());
        $order->addNote('The customer\'s details were changed.', NoteAuthor::System, false, $this->clock->now(), $userId);
        $this->orders->save($order);

        return $this->view($order, true);
    }

    /**
     * @throws OrderNotFound
     */
    public function addNote(int $id, AddOrderNote $command, ?int $userId): OrderView
    {
        $order = $this->get($id);
        $order->addNote($command->text, NoteAuthor::Shop, $command->forCustomer, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->events->dispatch(new OrderNoteAdded($order->id(), trim($command->text), $command->forCustomer));

        return $this->view($order, true);
    }

    /**
     * Moves the order to a customer's account, or makes it a guest's order; the history says so.
     *
     * @throws OrderNotFound
     */
    public function changeCustomer(int $id, ?int $customerId, ?int $userId): OrderView
    {
        $order = $this->get($id);
        if ($order->customerId() !== $customerId) {
            $order->changeCustomer($customerId);
            $order->addNote(
                $customerId === null ? 'Made a guest order.' : \sprintf('Moved to the account of customer #%d.', $customerId),
                NoteAuthor::Shop,
                false,
                $this->clock->now(),
                $userId,
            );
            $this->orders->save($order);
        }

        return $this->view($order, true);
    }

    /**
     * Changes how many times, and until when, the buyer may download one of the order's files.
     *
     * @throws OrderNotFound   also for a download the order does not have
     * @throws OrderRuleViolation
     */
    public function changeDownloadAllowance(int $id, int $grantId, ChangeDownloadAllowance $command, ?int $userId): OrderView
    {
        $order = $this->get($id);
        foreach ($order->grants() as $grant) {
            if ($grant->id() === $grantId) {
                $expires = $command->expiresAt === null ? null : new \DateTimeImmutable($command->expiresAt . ' 23:59:59', new \DateTimeZone('UTC'));
                $grant->allow($command->remaining, $expires);
                $order->addNote(
                    \sprintf('Downloads of %s: %s left, %s.', $grant->name(), $command->remaining ?? 'no limit', $command->expiresAt === null ? 'no end' : 'until ' . $command->expiresAt),
                    NoteAuthor::Shop,
                    false,
                    $this->clock->now(),
                    $userId,
                );
                $this->orders->save($order);

                return $this->view($order, true);
            }
        }

        throw new OrderNotFound();
    }

    /**
     * A customer who made an account after ordering gets the order in their account.
     *
     * @throws OrderNotFound
     */
    public function giveTo(int $id, int $customerId): void
    {
        $order = $this->get($id);
        $order->belongTo($customerId);
        $this->orders->save($order);
    }

    /**
     * Deletes an order for good. An order waiting for its payment gives its stock back first; a paid one has to
     * be cancelled or refunded before, so its money and stock are settled in its history and not just lost.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for a paid order
     */
    public function delete(int $id): void
    {
        $order = $this->get($id);
        if ($order->status()->isPaid()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s; cancel or refund it before deleting it.', $id, strtolower($order->status()->label())), 'orillacart_order_paid');
        }
        $taken = $order->cancel();
        if ($taken !== []) {
            $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken));
        }
        $this->orders->remove($order);
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
            $previous = $order->status();
            $this->changed($order, $previous, $order->cancel(), 'The payment was not finished within an hour.');
        }

        return \count($orders);
    }

    /**
     * Saves a status change: puts back the stock it freed, notes it in the order's history and announces it.
     *
     * @param list<TakenItem> $taken
     */
    private function changed(Order $order, OrderStatus $previous, array $taken, string $note, ?int $userId = null): void
    {
        if ($order->status() === $previous) {
            return;
        }
        if ($taken !== []) {
            $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken));
        }
        if ($order->grantDownloads(static fn(): string => bin2hex(random_bytes(32)), $this->clock->now())) {
            $order->addNote('The downloads were granted.', NoteAuthor::System, false, $this->clock->now());
        }
        $text = \sprintf('Status changed from %s to %s.', strtolower($previous->label()), strtolower($order->status()->label()));
        $order->addNote(trim($note) === '' ? $text : $text . ' ' . trim($note), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->events->dispatch(new OrderStatusChanged($order->id(), $order->status(), $previous));
    }

    /**
     * Frees the stock of an order on hold whose items the shop is about to change; replaceItems() takes the
     * new stock. Run both in one transaction.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    public function releaseStock(int $id): void
    {
        $order = $this->get($id);
        $taken = $order->releaseStock();
        if ($taken !== []) {
            $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken));
        }
        $this->orders->save($order);
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    public function replaceItems(int $id, NewOrderItems $command, ?int $userId): OrderView
    {
        $order = $this->get($id);
        $before = (string) $order->total();
        $order->replaceItems(
            self::lineDrafts($command->lines),
            self::shippingChoice($command->shipping),
            $command->shippingAddress?->toAddress(),
            self::taxLines($command->taxes),
            Money::of($command->subtotal),
            Money::of($command->itemsTax),
            Money::of($command->total),
        );
        $order->addNote(\sprintf('The items were changed; the total went from %s to %s %s.', $before, (string) $order->total(), $order->currency()), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);

        return $this->view($order, true);
    }

    /**
     * @param list<NewOrderLine> $lines
     *
     * @return list<LineDraft>
     */
    private static function lineDrafts(array $lines): array
    {
        return array_map(static fn(NewOrderLine $line): LineDraft => new LineDraft(
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
            array_map(static fn(QuotedDownload $file): LineDownload => new LineDownload($file->fileId, $file->name, $file->storageKey, $file->mimeType), $line->downloads),
            $line->downloadLimit,
            $line->downloadExpiryDays,
            $line->backordered,
            $line->backorderShown,
        ), $lines);
    }

    private static function shippingChoice(?NewShipping $shipping): ?ShippingChoice
    {
        return $shipping === null ? null : new ShippingChoice($shipping->rateId, $shipping->carrier, $shipping->rate, Money::of($shipping->price), Money::of($shipping->tax));
    }

    /**
     * @param list<TaxLineView> $taxes
     *
     * @return list<TaxLine>
     */
    private static function taxLines(array $taxes): array
    {
        return array_map(static fn(TaxLineView $tax): TaxLine => new TaxLine($tax->name, $tax->percent, Money::of($tax->amount)), $taxes);
    }

    private function view(Order $order, bool $forShop = false): OrderView
    {
        return OrderView::fromOrder($order, $forShop, $this->links->downloadUrl(...));
    }

    private function get(int $id): Order
    {
        return $this->orders->find($id) ?? throw new OrderNotFound();
    }
}
