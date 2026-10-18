<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\ChangeDownloadAllowance;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\NewOrderItems;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Ordering\Application\Dto\OrderPageView;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\Dto\OrderSummaryView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\FieldAnswer;
use OrillaCart\Ordering\Domain\LineDownload;
use OrillaCart\Ordering\Domain\LineDraft;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDiscount;
use OrillaCart\Ordering\Domain\OrderDraft;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRefund;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\ShippingChoice;
use OrillaCart\Ordering\Domain\TakenItem;
use OrillaCart\Ordering\Domain\TaxLine;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Recording placed orders and moving them along as their payment ends. Closing an order that never got paid
 * puts its stock back. Every change runs in one transaction with the order's row locked: the stock it gives back,
 * the coupon uses it releases, its history and its events stand or fall together, and two changes of one order
 * (a webhook and the shop, say) wait for each other.
 */
final readonly class OrderService
{
    /** How long a customer has to finish paying at a provider before the order is cancelled. */
    public const string PAYMENT_WINDOW = 'PT3H';

    public function __construct(
        private OrderRepository $orders,
        private StockService $stock,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private OrderLinks $links,
        private Transactions $transactions,
    ) {}

    public function place(NewOrder $command): OrderView
    {
        return $this->transactions->run(fn(): OrderView => $this->placed($command));
    }

    private function placed(NewOrder $command): OrderView
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
            Money::of($command->discountTotal),
            self::discounts($command->discounts),
            array_map(static fn(OrderFieldView $field): FieldAnswer => $field->toAnswer(), $command->fields),
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
    public function hold(int $id, string $note = '', bool $tellCustomer = true, bool $tellShop = true): void
    {
        $this->changing($id, function (Order $order) use ($note, $tellCustomer, $tellShop): void {
            $previous = $order->status();
            if ($order->hold()) {
                $this->changed($order, $previous, [], $note, tellCustomer: $tellCustomer, tellShop: $tellShop);
            }
        });
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that was cancelled or failed before the money came
     */
    public function markPaid(int $id, ?string $transactionId, string $note = '', bool $tellCustomer = true, bool $tellShop = true): void
    {
        $this->changing($id, function (Order $order) use ($transactionId, $note, $tellCustomer, $tellShop): void {
            $previous = $order->status();
            if ($order->markPaid($transactionId, $this->clock->now())) {
                $this->changed($order, $previous, [], $note, tellCustomer: $tellCustomer, tellShop: $tellShop);
            } elseif ($transactionId !== null && $transactionId !== $order->transactionId()) {
                $text = $order->paidAgain($transactionId)
                    ? \sprintf('Another payment came for this order, which was already paid (transaction %s): check whether to give one back.', $transactionId)
                    : \sprintf('The payment came for this order, which was already marked paid. %s', trim($note));
                $order->addNote(trim($text), NoteAuthor::System, false, $this->clock->now());
                $this->orders->save($order);
            }
        });
    }

    /**
     * The money came for an order that was cancelled or failed unpaid, as when the customer approved at the
     * provider after the payment window closed. It opens again as paid when every item it gave back is still in
     * stock, and takes them again.
     *
     * @return bool false when an item is no longer in stock: the order stays closed and the money must go back
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that did not close unpaid
     */
    public function paidAfterClosing(int $id, ?string $transactionId, string $note = ''): bool
    {
        try {
            $this->changing($id, function (Order $order) use ($transactionId, $note): void {
                $previous = $order->status();
                $order->reopenPaid($transactionId, $this->clock->now(), fn(array $wanted): array => array_map(
                    static fn(array $taken): array => array_map(static fn(TakenStock $part): TakenItem => new TakenItem($part->item, $part->stockroomId, $part->quantity), $taken),
                    $this->stock->takeAgain($wanted),
                ));
                $this->changed($order, $previous, [], trim(\sprintf('The money came after the order was %s, so it was opened again and its items taken again. %s', strtolower($previous->label()), $note)));
            });
        } catch (InsufficientStock) {
            return false;
        }

        return true;
    }

    /**
     * A note from the shop's own workings, for the customer to see too when they should.
     *
     * @throws OrderNotFound
     */
    public function note(int $id, string $text, bool $forCustomer): void
    {
        $this->changing($id, function (Order $order) use ($text, $forCustomer): void {
            $order->addNote($text, NoteAuthor::System, $forCustomer, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(new OrderNoteAdded($order->id(), trim($text), $forCustomer));
        });
    }

    /**
     * @throws OrderNotFound
     */
    public function fail(int $id, string $note = ''): void
    {
        $this->changing($id, function (Order $order) use ($note): void {
            $previous = $order->status();
            $this->changed($order, $previous, $order->fail(), $note);
        });
    }

    /**
     * @throws OrderNotFound
     */
    public function cancel(int $id, string $note = ''): void
    {
        $this->changing($id, function (Order $order) use ($note): void {
            $previous = $order->status();
            $this->changed($order, $previous, $order->cancel(), $note);
        });
    }

    /**
     * The shop moves an order along from its admin screen.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for a move the order's status does not allow
     */
    public function changeStatus(int $id, ChangeOrderStatus $command, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $previous = $order->status();
            $taken = $order->moveTo(OrderStatus::from($command->status), $this->clock->now());
            $this->changed($order, $previous, $taken, $command->note, $userId);

            return $this->view($order, true);
        });
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function changeContact(int $id, ChangeOrderContact $command, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $order->changeContact($command->email, $command->billing->toAddress(), $command->shippingAddress?->toAddress());
            $order->answer($command->fields);
            $order->addNote('The customer\'s details were changed.', NoteAuthor::System, false, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderChanged($order->id()));

            return $this->view($order, true);
        });
    }

    /**
     * @throws OrderNotFound
     */
    public function addNote(int $id, AddOrderNote $command, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $order->addNote($command->text, NoteAuthor::Shop, $command->forCustomer, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderNoteAdded($order->id(), trim($command->text), $command->forCustomer));

            return $this->view($order, true);
        });
    }

    /**
     * Moves the order to a customer's account, or makes it a guest's order; the history says so.
     *
     * @throws OrderNotFound
     */
    public function changeCustomer(int $id, ?int $customerId, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($customerId, $userId): OrderView {
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
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->view($order, true);
        });
    }

    /**
     * The customer's account was deleted: their orders stay, as guest orders under the email they gave.
     *
     * @return int how many orders changed
     */
    public function forgetCustomer(int $customerId): int
    {
        $changed = 0;
        do {
            [$orders] = $this->orders->search(null, null, $customerId, 0, 100);
            foreach ($orders as $order) {
                $this->changing($order->id(), function (Order $order) use ($customerId): void {
                    $order->changeCustomer(null);
                    $order->addNote(\sprintf('Made a guest order: the account of customer #%d was deleted.', $customerId), NoteAuthor::System, false, $this->clock->now());
                    $this->orders->save($order);
                    $this->events->dispatch(new OrderChanged($order->id()));
                });
                ++$changed;
            }
        } while ($orders !== []);

        return $changed;
    }

    /**
     * Changes how many times, and until when, the buyer may download one of the order's files.
     *
     * @throws OrderNotFound   also for a download the order does not have
     * @throws OrderRuleViolation
     */
    public function changeDownloadAllowance(int $id, int $grantId, ChangeDownloadAllowance $command, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($grantId, $command, $userId): OrderView {
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
        });
    }

    /**
     * A customer who made an account after ordering gets the order in their account.
     *
     * @throws OrderNotFound
     */
    public function giveTo(int $id, int $customerId): void
    {
        $this->changing($id, function (Order $order) use ($customerId): void {
            $order->belongTo($customerId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderChanged($order->id()));
        });
    }

    /**
     * Deletes an order for good. An order waiting for its payment gives its stock back first; a paid one has to
     * be refunded in full before, so its money and stock are settled in its history and not just lost.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for a paid order
     */
    public function delete(int $id): void
    {
        $this->changing($id, function (Order $order) use ($id): void {
            if ($order->status()->isPaid()) {
                throw new OrderRuleViolation(\sprintf('Order #%d is %s; refund it in full before deleting it.', $id, strtolower($order->status()->label())), 'orillacart_order_paid');
            }
            $this->giveBack($order->cancel());
            $this->orders->remove($order);
            $this->events->dispatch(new OrderDeleted($id));
        });
    }

    /**
     * Cancels orders whose customer went to pay at a provider and neither paid nor came back within the
     * payment window, so their stock is sold again.
     *
     * @return int how many were cancelled
     */
    public function cancelAbandoned(): int
    {
        $cancelled = 0;
        foreach ($this->orders->pendingPaymentSince($this->clock->now()->sub(new \DateInterval(self::PAYMENT_WINDOW))) as $found) {
            $cancelled += $this->changing($found->id(), function (Order $order): int {
                // Looked at again under the lock: the payment may have come in since the list was read.
                if ($order->status() !== OrderStatus::PendingPayment) {
                    return 0;
                }
                $this->changed($order, OrderStatus::PendingPayment, $order->cancel(), 'The payment was not finished within three hours.');

                return 1;
            });
        }

        return $cancelled;
    }

    /**
     * Saves a status change: puts back the stock it freed, notes it in the order's history and announces it.
     *
     * @param list<TakenItem> $taken
     */
    private function changed(Order $order, OrderStatus $previous, array $taken, string $note, ?int $userId = null, bool $tellCustomer = true, bool $tellShop = true): void
    {
        if ($order->status() === $previous) {
            return;
        }
        $this->giveBack($taken);
        if ($order->grantDownloads(static fn(): string => bin2hex(random_bytes(32)), $this->clock->now())) {
            $order->addNote('The downloads were granted.', NoteAuthor::System, false, $this->clock->now());
        }
        $text = \sprintf('Status changed from %s to %s.', strtolower($previous->label()), strtolower($order->status()->label()));
        $order->addNote(trim($note) === '' ? $text : $text . ' ' . trim($note), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->events->dispatch(new OrderStatusChanged($order->id(), $order->status(), $previous, $tellCustomer, $tellShop));
    }

    /**
     * The ids of the orders after the given id, in order: for going through every order a batch at a time.
     *
     * @return list<int>
     */
    public function ids(int $afterId, int $limit): array
    {
        return $this->orders->idsAfter($afterId, $limit);
    }

    /** Whether the customer, by account or by email, ordered before: an order that stands, not cancelled or failed. */
    public function hasOrdered(?int $customerId, string $email): bool
    {
        return $this->orders->hasStandingOrder($customerId, $email);
    }

    /**
     * Whether the refund fits the order, before any money moves.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function checkRefund(int $id, RefundOrder $command): void
    {
        $this->get($id)->checkRefund(self::orderRefund($command));
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
    public function refund(int $id, RefundOrder $command, string $how, ?int $userId, bool $tellCustomer = true): OrderView
    {
        return $this->changing($id, fn(Order $order): OrderView => $this->refunded($order, $command, $how, $userId, $tellCustomer));
    }

    private function refunded(Order $order, RefundOrder $command, string $how, ?int $userId, bool $tellCustomer): OrderView
    {
        $previous = $order->status();
        $refund = self::orderRefund($command);
        $this->giveBack($order->refund($refund));
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
        $this->events->dispatch(new OrderRefunded($order->id(), (string) $refund->amount, $full, $refund->quantities, $tellCustomer));
        if ($order->status() !== $previous) {
            // The refund's own email tells the customer.
            $this->events->dispatch(new OrderStatusChanged($order->id(), $order->status(), $previous, false, false));
        }

        return $this->view($order, true);
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
        $this->changing($id, function (Order $order): void {
            $this->giveBack($order->releaseStock());
            $this->orders->save($order);
        });
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    public function replaceItems(int $id, NewOrderItems $command, ?int $userId): OrderView
    {
        return $this->changing($id, fn(Order $order): OrderView => $this->itemsReplaced($order, $command, $userId));
    }

    private function itemsReplaced(Order $order, NewOrderItems $command, ?int $userId): OrderView
    {
        $before = (string) $order->total();
        $order->replaceItems(
            self::lineDrafts($command->lines),
            self::shippingChoice($command->shipping),
            $command->shippingAddress?->toAddress(),
            self::taxLines($command->taxes),
            Money::of($command->subtotal),
            Money::of($command->itemsTax),
            Money::of($command->total),
            Money::of($command->discountTotal),
            self::discounts($command->discounts),
        );
        $order->addNote(\sprintf('The items were changed; the total went from %s to %s %s.', $before, (string) $order->total(), $order->currency()), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->events->dispatch(new OrderChanged($order->id()));

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
            Money::of($line->discount),
            $line->contents,
            $line->bundleChoices,
        ), $lines);
    }

    private static function shippingChoice(?NewShipping $shipping): ?ShippingChoice
    {
        return $shipping === null ? null : new ShippingChoice($shipping->option, $shipping->carrier, $shipping->rate, Money::of($shipping->price), Money::of($shipping->tax), $shipping->pickupPointId, $shipping->pickupPoint);
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

    /**
     * @param list<OrderDiscountView> $discounts
     *
     * @return list<OrderDiscount>
     */
    private static function discounts(array $discounts): array
    {
        return array_map(static fn(OrderDiscountView $discount): OrderDiscount => new OrderDiscount($discount->code, $discount->description, Money::of($discount->amount), $discount->freeShipping, $discount->automatic), $discounts);
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
    private function changing(int $id, \Closure $work): mixed
    {
        return $this->transactions->run(fn(): mixed => $work($this->orders->find($id, true) ?? throw new OrderNotFound()));
    }

    /**
     * @param list<TakenItem> $taken
     */
    private function giveBack(array $taken): void
    {
        if ($taken !== []) {
            $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken));
        }
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
