<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\AdjustmentView;
use OrillaCart\Ordering\Application\Dto\ChangeDownloadAllowance;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\LifetimeValueView;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\NewOrderItems;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderFacetsView;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Ordering\Application\Dto\OrderPageView;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\Dto\OrderSummaryView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\RefundedView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\Dto\RefundOrder;
use OrillaCart\Ordering\Application\Dto\RestockItems;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Domain\DeliveryChoice;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Ordering\Domain\Event\OrderMarkingPaid;
use OrillaCart\Ordering\Domain\Event\OrderNeedsAttention;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderPaid;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderReaddressing;
use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Ordering\Domain\Event\OrderRepricing;
use OrillaCart\Ordering\Domain\Event\OrderStatusChanged;
use OrillaCart\Ordering\Domain\FieldAnswer;
use OrillaCart\Ordering\Domain\LineDownload;
use OrillaCart\Ordering\Domain\LineDraft;
use OrillaCart\Ordering\Domain\LineTax;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDraft;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRefund;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Ordering\Domain\ShippingChoice;
use OrillaCart\Ordering\Domain\TakenItem;
use OrillaCart\Ordering\Domain\TaxLine;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\TaxJurisdiction;
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
        private ReceiptLinks $receipts,
        private OrderLookups $lookups,
    ) {}

    public function place(NewOrder $command): OrderView
    {
        return $this->transactions->run(fn(): OrderView => $this->placed($command));
    }

    private function placed(NewOrder $command): OrderView
    {
        $order = new Order(new OrderDraft(
            $command->customerId,
            $command->email,
            $command->billing->toAddress(),
            $command->shippingAddress?->toAddress(),
            self::lineDrafts($command->lines),
            self::shippingChoice($command->shipping),
            Money::of($command->total),
            $command->currency,
            $command->paymentGateway,
            $command->paymentName,
            $command->paymentNote,
            $command->note,
            $this->clock->now(),
            array_map(static fn(OrderFieldView $field): FieldAnswer => $field->toAnswer(), $command->fields),
            $command->pricesIncludeTax,
            $command->vatNumber,
            $command->reverseCharge,
            $command->locale,
            $command->deliveryDate === null ? null : new DeliveryChoice(new \DateTimeImmutable($command->deliveryDate), $command->deliverySlot, $command->deliveryKey),
            $command->taxExemption,
        ));
        $order->addNote('Order placed.', NoteAuthor::System, false, $this->clock->now());
        $this->orders->save($order);
        $this->stock->record(self::takenOf($command->lines), new StockNote(StockReason::Sale, $order->id()));
        $this->events->dispatch(new OrderPlaced($order->id()));

        return $this->view($order);
    }

    /**
     * The receipt of an order, for whoever holds a link to it the shop made.
     *
     * @throws OrderNotFound      for a link the shop never made, or an order deleted since
     * @throws ReceiptLinkExpired for a link too old: a new one goes to the order's email
     */
    public function byReceipt(string $token): OrderView
    {
        [$number, $expired] = $this->receipts->read($token) ?? throw new OrderNotFound();
        if ($expired) {
            throw new ReceiptLinkExpired();
        }

        // As the customer sees it: without the shop's own notes.
        return $this->view($this->get($number));
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
     * How many orders each customer has, for a list of customers, in one query.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, int> by customer id; 0 for those without orders
     */
    public function orderCounts(array $customerIds): array
    {
        return $this->orders->countByCustomers($customerIds) + array_fill_keys($customerIds, 0);
    }

    /**
     * Customers' lifetime value in the shop's currency: one query for them all.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, LifetimeValueView> by customer id, for each of them
     */
    public function lifetimeValues(array $customerIds, string $currency, int $decimals): array
    {
        $spend = $this->orders->spendByCustomers($customerIds, $currency);
        $values = [];
        foreach ($customerIds as $id) {
            $of = $spend[$id] ?? ['paid_orders' => 0, 'paid' => '0', 'refunded' => '0', 'first' => null, 'last' => null];
            $spent = Money::of($of['paid'])->subtract(Money::of($of['refunded']))->atLeastZero();
            $values[$id] = new LifetimeValueView(
                $currency,
                (string) $spent->rounded($decimals),
                $of['paid_orders'],
                (string) $spent->proportion(Money::of('1'), Money::of((string) max(1, $of['paid_orders'])))->rounded($decimals),
                $of['first']?->format(\DATE_ATOM),
                $of['last']?->format(\DATE_ATOM),
            );
        }

        return $values;
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

    /**
     * The choices the shop's order filters offer.
     */
    public function facets(): OrderFacetsView
    {
        $facets = $this->orders->facets();
        $has = [
            ['value' => OrderSearch::GIFT_CARD, 'label' => 'Paid with a gift card'],
            ['value' => OrderSearch::BACKORDER, 'label' => 'Backordered items'],
            ['value' => OrderSearch::PERSONALISED, 'label' => 'Personalised items'],
            ['value' => OrderSearch::CUSTOMER_NOTE, 'label' => 'A note from the customer'],
            ['value' => OrderSearch::DOWNLOADS, 'label' => 'Downloads'],
        ];
        foreach ([...$this->lookups->marks(), ...$this->lookups->addOnFilters()] as $key => $label) {
            $has[] = ['value' => $key, 'label' => $label];
        }

        return new OrderFacetsView($facets['shipping'], $facets['countries'], $facets['currencies'], $has);
    }

    /**
     * @param bool $forShop the shop's list, which marks orders it has to look at, and finds them by what other
     *                      areas keep (tracking, invoice and coupon numbers)
     */
    public function orders(OrderQuery $query, bool $forShop = false): OrderPageView
    {
        $search = $query->search === null || trim($query->search) === '' ? null : trim($query->search);
        // Orders keep when they were placed in UTC.
        $moment = static fn(?string $at): ?\DateTimeImmutable => $at === null ? null : new \DateTimeImmutable($at)->setTimezone(new \DateTimeZone('UTC'));
        $date = static fn(?string $day): ?\DateTimeImmutable => $day === null ? null : new \DateTimeImmutable($day);
        $has = $query->has();
        $addOnFilters = $forShop ? $this->lookups->addOnFilters() : [];
        $markedIds = [];
        foreach (array_diff($has, OrderSearch::FACTS, array_keys($addOnFilters)) as $mark) {
            // A mark no area gives finds nothing.
            $markedIds[] = $this->lookups->marked($mark) ?? [];
        }
        [$orders, $total] = $this->orders->search(
            new OrderSearch(
                statuses: array_map(OrderStatus::from(...), $query->statuses()),
                text: $search,
                customerId: $query->customerId,
                attentionOnly: $forShop && $query->attention,
                placedFrom: $moment($query->placedFrom),
                placedBefore: $moment($query->placedBefore),
                gateway: $query->payment,
                minTotal: $query->minTotal === null ? null : Money::of($query->minTotal),
                maxTotal: $query->maxTotal === null ? null : Money::of($query->maxTotal),
                who: $query->placedBy,
                sortBy: $query->orderBy,
                ascending: $query->order === 'asc',
                foundIds: $search === null || !$forShop ? [] : $this->lookups->ordersMatching($search),
                paid: $query->paid,
                shipped: $query->shipped === null ? null : $query->shipped === 'yes',
                shippingName: $query->shipping,
                country: $query->country,
                deliveryFrom: $date($query->deliveryFrom),
                deliveryUntil: $date($query->deliveryUntil),
                facts: array_values(array_intersect($has, OrderSearch::FACTS)),
                markedIds: $markedIds,
                currency: $query->currency,
                addOnMarks: array_values(array_intersect($has, array_keys($addOnFilters))),
            ),
            ($query->page - 1) * $query->perPage,
            $query->perPage,
        );

        return new OrderPageView(array_map(static fn(Order $order): OrderSummaryView => OrderSummaryView::fromOrder($order, $forShop), $orders), $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
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
     * Gift cards or store credit pay part of an order being placed, or all of it. Run it in the transaction that
     * places the order and takes the money from them.
     *
     * @param list<array{label: string, amount: string}> $cards
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function payWithGiftCards(int $id, array $cards): void
    {
        $order = $this->get($id);
        $order->payWithGiftCards(array_map(static fn(array $card): array => ['label' => $card['label'], 'amount' => Money::of($card['amount'])], $cards));
        $this->orders->save($order);
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that was cancelled or failed before the money came
     */
    public function markPaid(int $id, ?string $transactionId, string $note = '', bool $tellCustomer = true, bool $tellShop = true, ?string $amount = null): void
    {
        $this->changing($id, function (Order $order) use ($transactionId, $note, $tellCustomer, $tellShop, $amount): void {
            $previous = $order->status();
            if ($order->markPaid($transactionId, $this->clock->now(), $amount === null ? null : Money::of($amount))) {
                $this->paidOtherThanTotal($order);
                $order->status() === $previous ? $this->paidWhileFulfilled($order, $note, null) : $this->changed($order, $previous, [], $note, tellCustomer: $tellCustomer, tellShop: $tellShop);
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
     * The shop says the money came, such as the cash of an order paid on delivery or a bank transfer. An order
     * that waited for it is fulfilled now; one being fulfilled keeps its status.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that is paid, cancelled, failed or refunded
     */
    public function markPaidByShop(int $id, string $note, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($note, $userId): OrderView {
            $previous = $order->status();
            if ($order->paidAt() !== null) {
                throw new OrderRuleViolation(\sprintf('Order #%d is paid already.', $order->id()), 'orillacart_order_paid');
            }
            $marking = new OrderMarkingPaid($order->id());
            $this->events->dispatch($marking);
            if (!$order->markPaid($marking->transactionId ?? $order->transactionId(), $this->clock->now())) {
                throw new OrderRuleViolation(\sprintf('Order #%d is paid already.', $order->id()), 'orillacart_order_paid');
            }
            $order->status() === $previous ? $this->paidWhileFulfilled($order, $note, $userId) : $this->changed($order, $previous, [], $note, $userId);

            return $this->view($order, true);
        });
    }

    /** A payment for another amount than the order's total, as when the order changed after it began: the shop looks. */
    private function paidOtherThanTotal(Order $order): void
    {
        if (!$order->paidAmount()->equals($order->total())) {
            $order->needsAttention(\sprintf('The payment was %s %s, but the order totals %s %s: ask for the difference or give it back.', $order->paidAmount(), $order->currency(), $order->total(), $order->currency()), $this->clock->now());
        }
    }

    /**
     * Paid while it was being fulfilled: its status stays, its downloads come, its history says so.
     */
    private function paidWhileFulfilled(Order $order, string $note, ?int $userId): void
    {
        if ($order->grantDownloads(static fn(): string => bin2hex(random_bytes(32)), $this->clock->now())) {
            $order->addNote('The downloads were granted.', NoteAuthor::System, false, $this->clock->now());
        }
        $order->addNote(trim('Paid. ' . trim($note)), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->events->dispatch(new OrderChanged($order->id()));
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
    public function paidAfterClosing(int $id, ?string $transactionId, string $note = '', ?string $amount = null): bool
    {
        try {
            $this->changing($id, function (Order $order) use ($transactionId, $note, $amount): void {
                $previous = $order->status();
                $order->reopenPaid($transactionId, $this->clock->now(), fn(array $wanted): array => array_map(
                    static fn(array $taken): array => array_map(static fn(TakenStock $part): TakenItem => new TakenItem($part->item, $part->stockroomId, $part->quantity), $taken),
                    $this->stock->takeAgain($wanted, new StockNote(StockReason::Sale, $order->id())),
                ), $amount === null ? null : Money::of($amount));
                $this->paidOtherThanTotal($order);
                $this->changed($order, $previous, [], trim(\sprintf('The money came after the order was %s, so it was opened again and its items taken again. %s', strtolower($previous->label()), $note)));
            });
        } catch (InsufficientStock) {
            return false;
        }

        return true;
    }

    /**
     * The customer pays the order now, with the method chosen: the order waits for that payment from now. A
     * failed order takes its stock again.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that cannot be paid now
     * @throws InsufficientStock  when a failed order's items sold out since
     */
    public function awaitPayment(int $id, string $gateway, string $name, string $note): OrderView
    {
        return $this->changing($id, function (Order $order) use ($gateway, $name, $note): OrderView {
            $previous = $order->status();
            $order->awaitPayment($gateway, $name, $note, $this->clock->now(), fn(array $wanted): array => array_map(
                static fn(array $taken): array => array_map(static fn(TakenStock $part): TakenItem => new TakenItem($part->item, $part->stockroomId, $part->quantity), $taken),
                $this->stock->takeAgain($wanted, new StockNote(StockReason::Sale, $order->id())),
            ));
            $text = \sprintf('The customer chose to pay by %s.', $name);
            if ($order->status() !== $previous) {
                $this->changed($order, $previous, [], $text);
            } else {
                $order->addNote($text, NoteAuthor::System, false, $this->clock->now());
                $this->orders->save($order);
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->view($order);
        });
    }

    /**
     * Erases the customer from the order, as a privacy request asks; the books keep what they must (see
     * Order::anonymise()).
     *
     * @throws OrderNotFound
     */
    public function anonymise(int $id, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($userId): OrderView {
            $email = $order->email();
            if ($order->anonymise($this->clock->now())) {
                $order->addNote('The customer\'s details were erased, as a privacy request asked.', $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
                $this->orders->save($order);
                $this->events->dispatch(new OrderAnonymised($order->id(), $email));
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->view($order, true);
        });
    }

    /**
     * A page of a person's orders, by their email or their account, for a privacy export or erasure.
     *
     * @return list<OrderView>
     */
    public function ofPerson(string $email, ?int $customerId, int $page, int $perPage): array
    {
        return array_map(fn(Order $order): OrderView => $this->view($order, true), $this->orders->ofPerson($email, $customerId, ($page - 1) * $perPage, $perPage));
    }

    /**
     * Flags the order for the shop to look at, whatever its status, and tells the shop.
     *
     * @throws OrderNotFound
     */
    public function needsAttention(int $id, string $reason): void
    {
        $this->changing($id, function (Order $order) use ($reason): void {
            if (!$order->needsAttention($reason, $this->clock->now())) {
                return;
            }
            $order->addNote($reason, NoteAuthor::System, false, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(new OrderNeedsAttention($order->id(), trim($reason)));
        });
    }

    /**
     * The shop looked at what the order needed attention for.
     *
     * @throws OrderNotFound
     */
    public function attended(int $id, ?int $userId): OrderView
    {
        return $this->changing($id, function (Order $order) use ($userId): OrderView {
            $reason = $order->attention();
            if ($order->attended()) {
                $order->addNote(\sprintf('Marked as handled: %s', str_replace("\n", ' ', (string) $reason)), NoteAuthor::Shop, false, $this->clock->now(), $userId);
                $this->orders->save($order);
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->view($order, true);
        });
    }

    /**
     * A note from the shop's own workings, for the customer to see too when they should.
     *
     * @throws OrderNotFound
     */
    public function note(int $id, string $text, bool $forCustomer): void
    {
        $this->changing($id, function (Order $order) use ($text, $forCustomer): void {
            $note = $order->addNote($text, NoteAuthor::System, $forCustomer, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(new OrderNoteAdded($order->id(), trim($text), $forCustomer, $note->id()));
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
            $shippingAddress = $command->shippingAddress?->toAddress();
            // Asked before anything changes: a shipment booked to the old address refuses it.
            if ($order->shipsElsewhere($shippingAddress)) {
                $this->events->dispatch(new OrderReaddressing($order->id()));
            }
            $order->changeContact($command->email, $command->billing->toAddress(), $shippingAddress);
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
            $note = $order->addNote($command->text, NoteAuthor::Shop, $command->forCustomer, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderNoteAdded($order->id(), trim($command->text), $command->forCustomer, $note->id()));

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
            [$orders] = $this->orders->search(OrderSearch::ofCustomer($customerId), 0, 100);
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
     * Deletes an order for good: only one that was cancelled or failed, so its stock is back already. One that was
     * ever paid stays, since it has an invoice the books must keep; so does one another area still holds, such as
     * a payment open at its provider or a shipment booked (see OrderDeleting).
     *
     * @throws OrderNotFound
     * @throws RuleViolation for an order that must stay
     */
    public function delete(int $id): void
    {
        $this->changing($id, function (Order $order) use ($id): void {
            if ($order->paidAt() !== null) {
                throw new OrderRuleViolation(\sprintf('Order #%d was paid: it stays, with its invoice, for the books.', $id), 'orillacart_order_paid');
            }
            if (!\in_array($order->status(), [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                throw new OrderRuleViolation(\sprintf('Order #%d is still open: cancel it first, then delete it.', $id), 'orillacart_order_open');
            }
            $this->events->dispatch(new OrderDeleting($id));
            $this->orders->remove($order);
            $this->events->dispatch(new OrderDeleted($id));
        });
    }

    /**
     * The unpaid orders on hold placed in the time given, with one of the payment methods: those to remind.
     *
     * @param list<string> $gateways
     *
     * @return list<int> their ids
     */
    public function unpaidOnHold(\DateTimeImmutable $placedFrom, \DateTimeImmutable $placedUntil, array $gateways): array
    {
        return $this->orders->unpaidOnHold($placedFrom, $placedUntil, $gateways);
    }

    /**
     * How many orders still to deliver each of a carrier's slots took, by day: for slots that take so many a day.
     *
     * @param string $carrier the start of the keys the orders count against: "5:"
     *
     * @return array<string, int> by "2026-10-21|morning"
     */
    public function deliveriesBooked(string $carrier, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        return $this->orders->deliveriesBooked($carrier, $from, $until);
    }

    /**
     * Cancels an unpaid order on hold that waited long enough, giving its stock back; the customer hears of it.
     * One paid or no longer on hold since it was found is left.
     *
     * @return bool whether it was cancelled
     *
     * @throws OrderNotFound
     */
    public function expireUnpaid(int $id, int $days): bool
    {
        return $this->changing($id, function (Order $order) use ($days): bool {
            if ($order->status() !== OrderStatus::OnHold || $order->paidAt() !== null) {
                return false;
            }
            $this->changed($order, OrderStatus::OnHold, $order->cancel(), \sprintf('The payment did not come within %d days.', $days));

            return true;
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
     * The ids of the orders after the given id, in order: for going through every order a batch at a time.
     *
     * @return list<int>
     */
    public function ids(int $afterId, int $limit): array
    {
        return $this->orders->idsAfter($afterId, $limit);
    }

    /**
     * Whether the customer, by account or by email, ordered before: an order that stands, not cancelled or failed.
     *
     * @param int|null $exceptOrderId an order not to count: the one being priced again
     */
    public function hasOrdered(?int $customerId, string $email, ?int $exceptOrderId = null): bool
    {
        return $this->orders->hasStandingOrder($customerId, $email, $exceptOrderId);
    }

    /**
     * Which of the products the customer paid for, by account or by email.
     *
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    public function boughtProducts(?int $customerId, string $email, array $productIds): array
    {
        return $this->orders->boughtProducts($customerId, $email, $productIds);
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
    public function refund(int $id, RefundOrder $command, string $how, ?int $userId, bool $tellCustomer = true): RefundedView
    {
        return $this->changing($id, fn(Order $order): RefundedView => $this->refunded($order, $command, $how, $userId, $tellCustomer));
    }

    private function refunded(Order $order, RefundOrder $command, string $how, ?int $userId, bool $tellCustomer): RefundedView
    {
        $previous = $order->status();
        $refund = self::orderRefund($command);
        $share = $order->refund($refund);
        $this->giveBack($share->taken, new StockNote(StockReason::Return, $order->id(), $userId));
        $taxes = array_map(static fn(TaxLine $tax): TaxLineView => new TaxLineView($tax->name, $tax->percent, (string) $tax->amount), $share->taxes);
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
        $this->events->dispatch(new OrderRefunded($order->id(), (string) $refund->amount, $full, $refund->quantities, $tellCustomer, (string) $share->tax, array_map(static fn(TaxLineView $tax): array => ['name' => $tax->name, 'percent' => $tax->percent, 'amount' => $tax->amount], $taxes)));
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
        return $this->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $quantities = [];
            foreach ($command->lines as $line) {
                $quantities[$line->line] = ($quantities[$line->line] ?? 0) + $line->quantity;
            }
            $this->giveBack($order->restock($quantities), new StockNote(StockReason::Return, $order->id(), $userId));
            $order->addNote(\sprintf('%d items came back and went back in stock.', array_sum($quantities)), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderChanged($order->id()));

            return $this->view($order, true);
        });
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
            $this->giveBack($order->releaseStock(), new StockNote(StockReason::Release, $order->id()));
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
        $this->events->dispatch(new OrderRepricing($order->id()));
        $before = (string) $order->total();
        $order->replaceItems(
            self::lineDrafts($command->lines),
            self::shippingChoice($command->shipping),
            $command->shippingAddress?->toAddress(),
            Money::of($command->total),
            $command->pricesIncludeTax,
        );
        $order->addNote(\sprintf('The items were changed; the total went from %s to %s %s.', $before, (string) $order->total(), $order->currency()), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->stock->record(self::takenOf($command->lines), new StockNote(StockReason::Sale, $order->id(), $userId));
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
            array_map(static fn(LineTaxView $tax): LineTax => new LineTax($tax->name, $tax->percent, new TaxJurisdiction($tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction), Money::of($tax->taxable), Money::of($tax->amount), $tax->compound), $line->taxes),
            $line->ships,
            array_map(static fn(TakenStock $taken): TakenItem => new TakenItem($taken->item, $taken->stockroomId, $taken->quantity), $line->taken),
            array_map(static fn(QuotedDownload $file): LineDownload => new LineDownload($file->fileId, $file->name, $file->storageKey, $file->mimeType), $line->downloads),
            $line->downloadLimit,
            $line->downloadExpiryDays,
            $line->backordered,
            $line->backorderShown,
            array_map(static fn(AdjustmentView $adjustment): PriceAdjustment => new PriceAdjustment($adjustment->kind, $adjustment->source, $adjustment->label, Money::of($adjustment->amount), $adjustment->scope, $adjustment->freeShipping, $adjustment->reason), $line->adjustments),
            $line->contents,
            $line->bundleChoices,
            $line->personalisation,
            $line->kind,
        ), $lines);
    }

    private static function shippingChoice(?NewShipping $shipping): ?ShippingChoice
    {
        return $shipping === null ? null : new ShippingChoice($shipping->option, $shipping->carrier, $shipping->rate, $shipping->pickupPointId, $shipping->pickupPoint);
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
    private function giveBack(array $taken, StockNote $note): void
    {
        if ($taken !== []) {
            $this->stock->giveBack(array_map(static fn(TakenItem $item): TakenStock => new TakenStock($item->item, $item->stockroomId, $item->quantity), $taken), $note);
        }
    }

    /**
     * @param list<NewOrderLine> $lines
     *
     * @return list<TakenStock> what the lines took, as they were priced
     */
    private static function takenOf(array $lines): array
    {
        return array_merge(...array_map(static fn(NewOrderLine $line): array => $line->taken, $lines));
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
