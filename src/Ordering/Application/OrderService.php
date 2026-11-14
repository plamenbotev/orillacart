<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Catalog\Application\Dto\QuotedDownload;
use OrillaCart\Customers\Application\Dto\LifetimeValueView;
use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\AdjustmentView;
use OrillaCart\Ordering\Application\Dto\ChangeDownloadAllowance;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
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
use OrillaCart\Ordering\Domain\Charge;
use OrillaCart\Ordering\Domain\DeliveryChoice;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderDeleting;
use OrillaCart\Ordering\Domain\Event\OrderNeedsAttention;
use OrillaCart\Ordering\Domain\Event\OrderNoteAdded;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use OrillaCart\Ordering\Domain\Event\OrderReaddressing;
use OrillaCart\Ordering\Domain\Event\OrderRepricing;
use OrillaCart\Ordering\Domain\FieldAnswer;
use OrillaCart\Ordering\Domain\GivenBy;
use OrillaCart\Ordering\Domain\KeptBy;
use OrillaCart\Ordering\Domain\LineDownload;
use OrillaCart\Ordering\Domain\LineDraft;
use OrillaCart\Ordering\Domain\LineTax;
use OrillaCart\Ordering\Domain\LineType;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDraft;
use OrillaCart\Ordering\Domain\OrderNote;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Ordering\Domain\ShippingChoice;
use OrillaCart\Ordering\Domain\TakenItem;
use OrillaCart\Settings\Application\ShopCalendar;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\TaxJurisdiction;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shared\Privacy\PersonalDataErasers;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Recording placed orders, reading them, and the shop's own changes to them: status, contact, customer, notes,
 * items, erasure. Their money is OrderPayments', what is given back OrderRefunds'. Every change runs through
 * OrderChanges: one transaction with the order's row locked, so the stock it gives back, the coupon uses it
 * releases, its history and its events stand or fall together, and two changes of one order (a webhook and the
 * shop, say) wait for each other.
 */
final readonly class OrderService
{
    /** How long a customer has to finish paying at a provider before the order is cancelled. */
    public const string PAYMENT_WINDOW = Order::PAYMENT_WINDOW;
    /** How long an order is left as it is before a manager erases its customer by hand. */
    public const int QUIET_DAYS = 30;

    private OrderChanges $changes;

    public function __construct(
        private OrderRepository $orders,
        private StockService $stock,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private OrderLinks $links,
        private Transactions $transactions,
        private ReceiptLinks $receipts,
        private OrderLookups $lookups,
        private ?ShopCalendar $calendar = null,
        private PersonalDataErasers $erasers = new PersonalDataErasers(),
    ) {
        $this->changes = new OrderChanges($orders, $stock, $events, $clock, $links, $transactions);
    }

    public function place(NewOrder $command): OrderView
    {
        return $this->transactions->run(fn(): OrderView => $this->placed($command));
    }

    private function placed(NewOrder $command): OrderView
    {
        $order = new Order(new OrderDraft(
            $command->customerId,
            $command->email,
            OrderAddresses::address($command->billing),
            ($command->shippingAddress === null ? null : OrderAddresses::address($command->shippingAddress)),
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
            $command->taxProvider,
            $command->importScheme,
            $command->importNumber,
            $command->shopCurrency,
            $command->exchangeRate,
            $command->buyerReference,
            $command->shopPaymentName,
            $command->shopFields,
        ));
        $order->addNote('Order placed.', NoteAuthor::System, false, $this->clock->now());
        if ($command->test) {
            $order->markAsTest();
            $order->addNote('A test order: it is not emailed and not counted.', NoteAuthor::System, false, $this->clock->now());
        }
        if ($command->callerKey !== null) {
            $order->placedByCaller($command->callerKey);
        }
        $this->orders->save($order);
        $this->stock->record(self::takenOf($command->lines), new StockNote(StockReason::Sale, $order->id()));
        $this->events->dispatch(new OrderPlaced($order->id()));

        return $this->changes->view($order);
    }

    /**
     * The receipt of an order, for whoever holds a link to it the shop made.
     *
     * @throws OrderNotFound      for a link the shop never made, or an order deleted since
     * @throws ReceiptLinkExpired for a link too old: a new one goes to the order's email
     */
    public function byReceipt(#[\SensitiveParameter] string $token): OrderView
    {
        [$number, $expired] = $this->receipts->read($token) ?? throw new OrderNotFound();
        if ($expired) {
            throw new ReceiptLinkExpired();
        }

        // As the customer sees it: without the shop's own notes.
        return $this->changes->view($this->changes->get($number));
    }

    /**
     * An order as the shop sees it, with all its notes.
     *
     * @throws OrderNotFound
     */
    public function order(int $id): OrderView
    {
        return $this->changes->view($this->changes->get($id), true);
    }

    /**
     * An order as the shop sees it, in the words the customer read (design 51): for what the shop sends them, its
     * emails and invoices.
     *
     * @throws OrderNotFound
     */
    public function inCustomerWords(int $id): OrderView
    {
        return $this->changes->view($this->changes->get($id), true, false);
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
        $order = $this->changes->get($id);
        if ($order->customerId() !== $customerId) {
            throw new OrderNotFound();
        }

        return $this->changes->view($order);
    }

    /**
     * A tax service recorded the order's sale under its reference, for later refunds.
     *
     * @throws OrderNotFound
     */
    public function taxRecorded(int $number, string $provider, string $reference): void
    {
        $this->transactions->run(function () use ($number, $provider, $reference): void {
            $order = $this->orders->find($number, true) ?? throw new OrderNotFound();
            $order->taxRecorded($provider, $reference);
            $order->addNote(\sprintf('The tax service %s recorded the sale as %s.', $provider, $reference), NoteAuthor::System, false, $this->clock->now());
            $this->orders->save($order);
        });
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
        [$orders, $total] = $this->orders->search($this->search($query, $forShop), ($query->page - 1) * $query->perPage, $query->perPage);

        return new OrderPageView(array_map(static fn(Order $order): OrderSummaryView => OrderSummaryView::fromOrder($order, $forShop), $orders), $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
    }

    /**
     * What the list asks the orders for: its filters as the records search them, for orders() and for an export
     * that walks every match.
     *
     * @param bool $forShop as orders() has it
     */
    public function search(OrderQuery $query, bool $forShop = false): OrderSearch
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
        return new OrderSearch(
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
            test: $query->test === null ? null : $query->test === 'yes',
        );
    }

    /**
     * Erases the customer from the order, as a privacy request asks; the books keep what they must (see
     * Order::anonymise()).
     *
     * By hand, without a privacy request the person confirmed, an order is erased only once it has been left as
     * it is for QUIET_DAYS: the change log keeps what a manager changed on an order until the order is erased, and
     * a change made just before (a new email for its downloads, then the order cancelled) stays to be seen for that
     * long whatever is done to the order after.
     *
     * @param \DateTimeImmutable|null $openUnlessPlacedBefore by hand: an order still open (not completed,
     *                                                      cancelled, refunded or failed) placed since then is
     *                                                      refused, and so is any order changed in the last
     *                                                      QUIET_DAYS
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function anonymise(int $id, ?int $userId, ?\DateTimeImmutable $openUnlessPlacedBefore = null): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($userId, $openUnlessPlacedBefore): OrderView {
            if ($openUnlessPlacedBefore !== null && $order->anonymisedAt() === null) {
                if (!self::settled($order->status()) && $order->placedAt() >= $openUnlessPlacedBefore) {
                    throw new OrderRuleViolation('Order #%d is still open: erase its customer once it is completed, cancelled, refunded or failed.', 'orillacart_order_open', values: [$order->id()]);
                }
                $quietSince = $this->clock->now()->modify('-' . self::QUIET_DAYS . ' days');
                // Each change to an order leaves a note: its status, its customer's details, its items, a payment.
                $notes = $order->notes();
                $last = $notes === [] ? $order->placedAt() : max(array_map(static fn(OrderNote $note): \DateTimeImmutable => $note->createdAt(), $notes));
                if ($last > $quietSince) {
                    throw new OrderRuleViolation('Order #%d changed in the last %d days: erase its customer once it has been left as it is that long.', 'orillacart_order_recent', values: [$order->id(), self::QUIET_DAYS]);
                }
            }
            $email = $order->email();
            if ($order->anonymise($this->clock->now())) {
                $order->addNote('The customer\'s details were erased, as a privacy request asked.', $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
                $this->orders->save($order);
                $this->erasers->erasedFromOrder($order->id(), $email);
                $this->events->dispatch(new OrderAnonymised($order->id(), $email));
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->changes->view($order, true);
        });
    }

    /**
     * A page of a person's orders, by their email or their account, for a privacy export or erasure.
     *
     * @return list<OrderView>
     */
    public function ofPerson(string $email, ?int $customerId, int $page, int $perPage): array
    {
        return array_map(fn(Order $order): OrderView => $this->changes->view($order, true), $this->orders->ofPerson($email, $customerId, ($page - 1) * $perPage, $perPage));
    }

    /**
     * Flags the order for the shop to look at, whatever its status, and tells the shop.
     *
     * @throws OrderNotFound
     */
    public function needsAttention(int $id, string $reason): void
    {
        $this->changes->changing($id, function (Order $order) use ($reason): void {
            // Flagged already for something else: the shop still reads this in the history.
            $flagged = $order->needsAttention($reason, $this->clock->now());
            $order->addNote($reason, NoteAuthor::System, false, $this->clock->now());
            $this->orders->save($order);
            if ($flagged) {
                $this->events->dispatch(new OrderNeedsAttention($order->id(), trim($reason)));
            }
        });
    }

    /**
     * The shop looked at what the order needed attention for.
     *
     * @throws OrderNotFound
     */
    public function attended(int $id, ?int $userId): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($userId): OrderView {
            $reason = $order->attention();
            if ($order->attended()) {
                $order->addNote(\sprintf('Marked as handled: %s', str_replace("\n", ' ', (string) $reason)), NoteAuthor::Shop, false, $this->clock->now(), $userId);
                $this->orders->save($order);
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->changes->view($order, true);
        });
    }

    /**
     * A note from the shop's own workings, for the customer to see too when they should.
     *
     * @throws OrderNotFound
     */
    public function note(int $id, string $text, bool $forCustomer): void
    {
        $this->changes->changing($id, function (Order $order) use ($text, $forCustomer): void {
            $note = $order->addNote($text, NoteAuthor::System, $forCustomer, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(new OrderNoteAdded($order->id(), trim($text), $forCustomer, $note->id()));
        });
    }

    /**
     * @throws OrderNotFound
     */
    public function cancel(int $id, string $note = ''): void
    {
        $this->changes->changing($id, function (Order $order) use ($note): void {
            $previous = $order->status();
            $this->changes->changed($order, $previous, $order->cancel(), $note);
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
        return $this->changes->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $previous = $order->status();
            $taken = $order->moveTo(OrderStatus::from($command->status), $this->clock->now());
            $this->changes->changed($order, $previous, $taken, $command->note, $userId);

            return $this->changes->view($order, true);
        });
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation
     */
    public function changeContact(int $id, ChangeOrderContact $command, ?int $userId): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $shippingAddress = ($command->shippingAddress === null ? null : OrderAddresses::address($command->shippingAddress));
            // Asked before anything changes: a shipment booked to the old address refuses it.
            if ($order->shipsElsewhere($shippingAddress)) {
                $this->events->dispatch(new OrderReaddressing($order->id()));
            }
            $order->changeContact($command->email, OrderAddresses::address($command->billing), $shippingAddress);
            $order->answer($command->fields);
            $order->addNote('The customer\'s details were changed.', NoteAuthor::System, false, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderChanged($order->id()));

            return $this->changes->view($order, true);
        });
    }

    /**
     * @throws OrderNotFound
     */
    public function addNote(int $id, AddOrderNote $command, ?int $userId): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($command, $userId): OrderView {
            $note = $order->addNote($command->text, NoteAuthor::Shop, $command->forCustomer, $this->clock->now(), $userId);
            $this->orders->save($order);
            $this->events->dispatch(new OrderNoteAdded($order->id(), trim($command->text), $command->forCustomer, $note->id()));

            return $this->changes->view($order, true);
        });
    }

    /**
     * Moves the order to a customer's account, or makes it a guest's order; the history says so.
     *
     * @throws OrderNotFound
     */
    public function changeCustomer(int $id, ?int $customerId, ?int $userId): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($customerId, $userId): OrderView {
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

            return $this->changes->view($order, true);
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
                $this->changes->changing($order->id(), function (Order $order) use ($customerId): void {
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
        return $this->changes->changing($id, function (Order $order) use ($grantId, $command, $userId): OrderView {
            foreach ($order->grants() as $grant) {
                if ($grant->id() === $grantId) {
                    // The last day the buyer may download, in the shop's calendar: until the end of that day there.
                    $expires = $command->expiresAt === null ? null : new \DateTimeImmutable($command->expiresAt . ' 23:59:59', $this->calendar?->timezone() ?? new \DateTimeZone('UTC'))->setTimezone(new \DateTimeZone('UTC'));
                    $grant->allow($command->remaining, $expires);
                    $order->addNote(
                        \sprintf('Downloads of %s: %s left, %s.', $grant->name(), $command->remaining ?? 'no limit', $command->expiresAt === null ? 'no end' : 'until ' . $command->expiresAt),
                        NoteAuthor::Shop,
                        false,
                        $this->clock->now(),
                        $userId,
                    );
                    $this->orders->save($order);

                    return $this->changes->view($order, true);
                }
            }

            throw new OrderNotFound();
        });
    }

    /**
     * The orders placed with the email by a guest: those a new account of the email takes.
     *
     * @return list<int>
     */
    public function guestOrdersOf(string $email): array
    {
        $ids = [];
        for ($offset = 0; ; $offset += 100) {
            $found = $this->orders->ofPerson($email, null, $offset, 100);
            foreach ($found as $order) {
                if ($order->customerId() === null) {
                    $ids[] = $order->id();
                }
            }
            if (\count($found) < 100) {
                return $ids;
            }
        }
    }

    /**
     * A customer who made an account after ordering gets the order in their account.
     *
     * @throws OrderNotFound
     */
    public function giveTo(int $id, int $customerId): void
    {
        $this->changes->changing($id, function (Order $order) use ($customerId): void {
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
        $this->changes->changing($id, function (Order $order) use ($id): void {
            if ($order->paidAt() !== null) {
                throw new OrderRuleViolation('Order #%d was paid: it stays, with its invoice, for the books.', 'orillacart_order_paid', values: [$id]);
            }
            if (!\in_array($order->status(), [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
                throw new OrderRuleViolation('Order #%d is still open: cancel it first, then delete it.', 'orillacart_order_open', values: [$id]);
            }
            $this->events->dispatch(new OrderDeleting($id));
            $this->orders->remove($order);
            $this->events->dispatch(new OrderDeleted($id));
        });
    }

    /**
     * Removes a test order (design 62), whatever its status: an open one is cancelled first, so the stock it took
     * comes back, then it goes with all it kept. A sale is never removed this way.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that is not a test order
     */
    public function removeTest(int $id): void
    {
        $order = $this->changes->get($id);
        if (!$order->isTest()) {
            throw new OrderRuleViolation('Order #%d is a sale, not a test order: it stays.', 'orillacart_order_not_test', values: [$id]);
        }
        // Still open: cancelled, so the stock it took comes back. One shipped or completed keeps its stock as sold.
        if (\in_array($order->status(), [OrderStatus::PendingPayment, OrderStatus::OnHold, OrderStatus::Processing], true)) {
            $this->cancel($id, 'Removed with the test data.');
        }
        $this->changes->changing($id, function (Order $order) use ($id): void {
            $this->events->dispatch(new OrderDeleting($id));
            $this->orders->remove($order);
            $this->events->dispatch(new OrderDeleted($id, test: true));
        });
    }

    /**
     * The test orders' numbers, a page at a time (design 62).
     *
     * @return list<int>
     */
    public function testOrders(int $limit = 200): array
    {
        [$orders] = $this->orders->search(new OrderSearch(test: true), 0, $limit);

        return array_map(static fn(Order $order): int => $order->id(), $orders);
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
     * The ids of the orders after the given id, in order: for going through every order a batch at a time.
     *
     * @return list<int>
     */
    public function ids(int $afterId, int $limit): array
    {
        return $this->orders->idsAfter($afterId, $limit);
    }

    /**
     * How many orders of the customer's account are on hold unpaid, waiting for a payment by one of the gateways:
     * each holds its stock until the money comes or the shop cancels it.
     *
     * @param list<string> $gateways
     */
    public function countUnpaidOnHold(int $customerId, array $gateways): int
    {
        return $gateways === [] ? 0 : $this->orders->countUnpaidOnHold($customerId, $gateways);
    }

    /**
     * How many guests' orders placed from one place (NewOrder::$callerKey) are on hold unpaid, waiting for a
     * payment by one of the gateways.
     *
     * @param list<string> $gateways
     */
    public function countUnpaidOnHoldOfCaller(string $callerKey, array $gateways): int
    {
        return $gateways === [] ? 0 : $this->orders->countUnpaidOnHoldOfCaller($callerKey, $gateways);
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
     * Frees the stock of an order on hold whose items the shop is about to change; replaceItems() takes the
     * new stock. Run both in one transaction.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    public function releaseStock(int $id): void
    {
        $this->changes->changing($id, function (Order $order): void {
            $this->changes->giveBack($order->releaseStock(), new StockNote(StockReason::Release, $order->id()));
            $this->orders->save($order);
        });
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    public function replaceItems(int $id, NewOrderItems $command, ?int $userId): OrderView
    {
        return $this->changes->changing($id, fn(Order $order): OrderView => $this->itemsReplaced($order, $command, $userId));
    }

    private function itemsReplaced(Order $order, NewOrderItems $command, ?int $userId): OrderView
    {
        $this->events->dispatch(new OrderRepricing($order->id()));
        $before = (string) $order->total();
        $order->replaceItems(
            self::lineDrafts($command->lines),
            self::shippingChoice($command->shipping),
            ($command->shippingAddress === null ? null : OrderAddresses::address($command->shippingAddress)),
            Money::of($command->total),
            $command->pricesIncludeTax,
            $command->taxExemption,
            $command->taxProvider,
        );
        $order->addNote(\sprintf('The items were changed; the total went from %s to %s %s.', $before, (string) $order->total(), $order->currency()), $userId === null ? NoteAuthor::System : NoteAuthor::Shop, false, $this->clock->now(), $userId);
        $this->orders->save($order);
        $this->stock->record(self::takenOf($command->lines), new StockNote(StockReason::Sale, $order->id(), $userId));
        $this->events->dispatch(new OrderChanged($order->id()));

        return $this->changes->view($order, true);
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
            array_map(static fn(LineTaxView $tax): LineTax => new LineTax($tax->name, $tax->percent, new TaxJurisdiction($tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction), Money::of($tax->taxable), Money::of($tax->amount), $tax->compound, $tax->exempt), $line->taxes),
            $line->ships,
            array_map(static fn(TakenStock $taken): TakenItem => new TakenItem($taken->item, $taken->stockroomId, $taken->quantity), $line->taken),
            array_map(static fn(QuotedDownload $file): LineDownload => new LineDownload($file->fileId, $file->name, $file->storageKey, $file->mimeType), $line->downloads),
            $line->downloadLimit,
            $line->downloadExpiryDays,
            $line->backordered,
            $line->backorderShown,
            array_map(static fn(AdjustmentView $adjustment): PriceAdjustment => new PriceAdjustment($adjustment->kind, $adjustment->source, $adjustment->label, Money::of($adjustment->amount), $adjustment->scope, $adjustment->freeShipping, $adjustment->reason, $adjustment->change), $line->adjustments),
            $line->contents,
            $line->bundleChoices,
            $line->personalisation,
            LineType::from($line->type),
            $line->shopWords,
            $line->givenCode === null ? null : new GivenBy($line->givenCode, $line->givenDescription),
            $line->addOnType === null ? null : new KeptBy($line->addOnType, $line->addOnLabel),
            $line->chargeSource === null ? null : new Charge($line->chargeSource, $line->chargeTaxable, $line->chargeTaxGroupId, $line->chargeFor),
        ), $lines);
    }

    private static function shippingChoice(?NewShipping $shipping): ?ShippingChoice
    {
        return $shipping === null ? null : new ShippingChoice($shipping->option, $shipping->carrier, $shipping->rate, $shipping->pickupPointId, $shipping->pickupPoint, $shipping->shopCarrier, $shipping->shopRate, $shipping->shopPickupPoint);
    }

    /**
     * Runs work about an order, such as another area's records of it, in one transaction with the order's row
     * locked: two requests about the same order then run one after the other.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     *
     * @throws OrderNotFound
     */
    public function whileLocked(int $id, \Closure $work): mixed
    {
        return $this->changes->changing($id, static fn(): mixed => $work());
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

    /** Whether nothing more happens to an order of the status: completed, cancelled, refunded, failed. */
    private static function settled(OrderStatus $status): bool
    {
        return $status === OrderStatus::Completed || $status->isClosed();
    }
}
