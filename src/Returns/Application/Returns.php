<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Coupons\Application\EarnedItems;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\Charge;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\DeclareWithdrawal;
use OrillaCart\Returns\Application\Dto\FindWithdrawableOrder;
use OrillaCart\Returns\Application\Dto\OrderReturnsView;
use OrillaCart\Returns\Application\Dto\RefundReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnedItemView;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Application\Dto\ReturnView;
use OrillaCart\Returns\Application\Dto\WithdrawableOrderView;
use OrillaCart\Returns\Application\Dto\WithdrawalView;
use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnNotFound;
use OrillaCart\Returns\Domain\ReturnReason;
use OrillaCart\Returns\Domain\ReturnRepository;
use OrillaCart\Returns\Domain\ReturnRuleViolation;
use OrillaCart\Returns\Domain\ReturnStatus;
use OrillaCart\Returns\Domain\WithdrawalOrderNotFound;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\FreeItemsOnReturn;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;
use Psr\Clock\ClockInterface;

/**
 * Items customers send back. Within the shop's return window, counted from when the order left, the customer asks
 * from the receipt, for items that shipped and were not refunded or asked for already, saying why; the shop hears
 * of it (the order needs attention), approves it with how to send the items or refuses it saying why (the customer
 * gets the answer as a note), says when the items came back, and refunds them, putting them back in stock.
 */
final readonly class Returns
{
    private const array CLOSED = ['cancelled', 'failed', 'refunded'];

    public function __construct(
        private ReturnRepository $returns,
        private OrderService $orders,
        private Refunds $refunds,
        private SettingsService $settings,
        private ClockInterface $clock,
        private Translations $translations,
        private Jobs $jobs,
        private EarnedItems $earned,
    ) {}

    /**
     * The order's returns as the shop sees them, in its own words.
     *
     * @throws OrderNotFound
     */
    public function forOrder(int $orderId): OrderReturnsView
    {
        return $this->view($this->orders->order($orderId));
    }

    /**
     * The order's returns as its customer sees them, in the words they read (design 51).
     *
     * @throws OrderNotFound
     */
    public function forCustomer(int $orderId): OrderReturnsView
    {
        return $this->view($this->orders->inCustomerWords($orderId));
    }

    /**
     * The order's returns as the customer holding its receipt sees them.
     *
     * @throws OrderNotFound
     * @throws RuleViolation for a link that expired
     */
    public function forReceipt(string $receipt): OrderReturnsView
    {
        return $this->view($this->orders->byReceipt($receipt));
    }

    /**
     * @throws OrderNotFound
     * @throws RuleViolation       for a link that expired
     * @throws ReturnRuleViolation for an order not to return now, or items that cannot go back
     */
    public function request(string $receipt, RequestReturn $command): ReturnView
    {
        $order = $this->orders->byReceipt($receipt);

        // The order locked: a request sent twice makes one return, the second finding the items held already.
        return $this->orders->whileLocked($order->number, fn(): ReturnView => $this->requested($this->orders->inCustomerWords($order->number), $command));
    }

    /**
     * @throws ReturnRuleViolation for an order not to return now, or items that cannot go back
     */
    private function requested(OrderView $order, RequestReturn $command): ReturnView
    {
        if (!$this->accepting($order)) {
            throw new ReturnRuleViolation('This order cannot be returned now.', 'orillacart_return_not_accepted');
        }
        $left = $this->returnable($order);
        $items = [];
        foreach ($command->items as $item) {
            \assert($item instanceof ReturnItem);
            $items[$item->line] = ($items[$item->line] ?? 0) + $item->quantity;
        }
        foreach ($items as $line => $quantity) {
            if ($quantity > ($left[$line] ?? 0)) {
                throw new ReturnRuleViolation('%1$d of "%2$s" can go back.', 'orillacart_invalid_return_items', values: [$left[$line] ?? 0, self::lineAt($order, $line)->name ?? (string) ($line + 1)]);
            }
        }
        ksort($items);
        if ($this->settings->current()->freeItemsOnReturn === FreeItemsOnReturn::ComeBack->value) {
            foreach ($this->unearned($order, $items) as $position => $units) {
                throw new ReturnRuleViolation('Send back %1$d of "%2$s" too: it came free with the items you return.', 'orillacart_return_free_items', values: [$units, $order->lines[$position]->name]);
            }
        }
        $items = $this->withCharges($order, $items);
        $return = new ItemReturn($order->number, $items, ReturnReason::from($command->reason), $command->comment, $this->clock->now());
        $this->returns->save($return);
        // The shop reads the items in its own words, the customer in theirs (design 51).
        $this->orders->needsAttention($order->number, \sprintf('The customer asks to return %s: %s.%s', self::listed($this->orders->order($order->number), $items), strtolower((string) $return->reason()->label()), $return->comment() === '' ? '' : ' "' . $return->comment() . '"'));

        return $this->returnView($order, $return);
    }

    /**
     * The order a consumer withdraws from, found by its number and the email it was placed with, and what of it
     * they may withdraw from: every item not refunded or returned already, delivered or not.
     *
     * @throws WithdrawalOrderNotFound for no order with that number and email, said the same either way
     * @throws ReturnRuleViolation for an order that is closed
     */
    public function withdrawable(FindWithdrawableOrder $command): WithdrawableOrderView
    {
        $order = $this->ordered($command->number, $command->email);
        $lines = [];
        foreach ($this->withdrawableItems($order) as $line => $quantity) {
            $lines[] = ['line' => $line, 'name' => self::lineAt($order, $line)->name ?? '', 'quantity' => $quantity];
        }

        return new WithdrawableOrderView($order->number, $order->placedAt, trim($order->billing->firstName . ' ' . $order->billing->lastName), $lines);
    }

    /**
     * Records a consumer's withdrawal, as the law's withdrawal function asks: in time whenever it is sent within
     * the period, so it is taken as it comes and the shop judges it; it holds the items as a return does, the order
     * needs the shop's attention, and the consumer is told at once what they declared and when.
     *
     * @throws WithdrawalOrderNotFound
     * @throws ReturnRuleViolation     for an order that is closed, or items it does not have left
     */
    public function withdraw(DeclareWithdrawal $command): WithdrawalView
    {
        $order = $this->ordered($command->number, $command->email);

        return $this->orders->whileLocked($order->number, function () use ($command, $order): WithdrawalView {
            $order = $this->orders->inCustomerWords($order->number);
            $left = $this->withdrawableItems($order);
            $items = [];
            foreach ($command->items as $item) {
                \assert($item instanceof ReturnItem);
                $items[$item->line] = ($items[$item->line] ?? 0) + $item->quantity;
            }
            foreach ($items as $line => $quantity) {
                if ($quantity > ($left[$line] ?? 0)) {
                    throw new ReturnRuleViolation('%1$d of "%2$s" are left to withdraw from.', 'orillacart_invalid_return_items', values: [$left[$line] ?? 0, self::lineAt($order, $line)->name ?? (string) ($line + 1)]);
                }
            }
            // None chosen: the whole contract.
            $items = $items === [] ? $left : array_filter($items, static fn(int $quantity): bool => $quantity > 0);
            if ($items === []) {
                throw new ReturnRuleViolation('Nothing of this order is left to withdraw from.', 'orillacart_nothing_to_withdraw');
            }
            $items = $this->withCharges($order, $items);
            $now = $this->clock->now();
            $name = trim($command->name);
            $confirmation = trim($command->confirmationEmail);
            $return = new ItemReturn($order->number, $items, ReturnReason::Withdrawal, \sprintf('Declared by %s on %s; confirmation to %s.', $name, $now->format(DATE_ATOM), $confirmation), $now);
            $this->returns->save($return);
            $this->orders->needsAttention($order->number, \sprintf('The customer withdrew from %s (declared by %s on %s).', self::listed($this->orders->order($order->number), $items), $name, $now->format('Y-m-d H:i T')));
            $listed = [];
            foreach ($items as $line => $quantity) {
                $listed[] = ['name' => self::lineAt($order, $line)->name ?? '', 'quantity' => $quantity];
            }
            // The acknowledgement goes out from a job, not from inside the order's lock: a slow mail server holds
            // no order up, and the mail goes only once the withdrawal is saved.
            $this->jobs->push(SendWithdrawalAcknowledgements::TYPE, ['order' => $order->number, 'return' => $return->id(), 'name' => $name, 'confirmation' => $confirmation, 'declared_at' => $now->format(DATE_ATOM)]);

            return new WithdrawalView($order->number, $name, $confirmation, $listed, $now->format(DATE_ATOM));
        });
    }

    /**
     * @throws WithdrawalOrderNotFound the same for a number that is not an order and an email that is not its
     * @throws ReturnRuleViolation for an order closed: cancelled, failed or refunded
     */
    private function ordered(int $number, string $email): OrderView
    {
        try {
            $order = $this->orders->inCustomerWords($number);
        } catch (OrderNotFound) {
            $order = null;
        }
        if ($order === null || strcasecmp($order->email, trim($email)) !== 0) {
            throw new WithdrawalOrderNotFound();
        }
        if (\in_array($order->status, self::CLOSED, true)) {
            throw new ReturnRuleViolation('This order is closed: there is nothing to withdraw from.', 'orillacart_order_closed');
        }

        return $order;
    }

    /**
     * What may be withdrawn from: every line's items, shipped or not, less those refunded and those a return holds.
     *
     * @return array<int, int> quantities by line position
     */
    private function withdrawableItems(OrderView $order): array
    {
        $left = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            $left[$position] = $line->quantity - $line->refundedQuantity;
        }
        foreach ($this->returns->forOrder($order->number) as $return) {
            if ($return->status()->holdsItems()) {
                foreach ($return->items() as $position => $quantity) {
                    $left[$position] = ($left[$position] ?? 0) - $quantity;
                }
            }
        }

        return array_filter($left, static fn(int $quantity): bool => $quantity > 0);
    }

    /**
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return answered already
     */
    public function approve(int $id, AnswerReturn $command, ?int $userId): ReturnView
    {
        return $this->changing($id, function (ItemReturn $return, OrderView $order) use ($command, $userId): void {
            $return->approve($command->answer, $this->clock->now());
            $this->returns->save($return);
            // The customer reads the note: the items in their words (design 51).
            $this->orders->addNote($order->number, new AddOrderNote(trim($this->translations->text(Text::of('Your return of %1$s is approved. %2$s', self::listed($this->orders->inCustomerWords($order->number), $return->items()), $return->answer()))), true), $userId);
        });
    }

    /**
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return answered already, or no reason given
     */
    public function refuse(int $id, AnswerReturn $command, ?int $userId): ReturnView
    {
        return $this->changing($id, function (ItemReturn $return, OrderView $order) use ($command, $userId): void {
            $return->refuse($command->answer, $this->clock->now());
            $this->returns->save($return);
            $this->orders->addNote($order->number, new AddOrderNote($this->translations->text(Text::of('Your return of %1$s is refused: %2$s', self::listed($this->orders->inCustomerWords($order->number), $return->items()), $return->answer())), true), $userId);
        });
    }

    /**
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return refused, or received already
     */
    public function receive(int $id, ?int $userId): ReturnView
    {
        return $this->changing($id, function (ItemReturn $return, OrderView $order) use ($userId): void {
            $return->receive($this->clock->now());
            $this->returns->save($return);
            $this->orders->addNote($order->number, new AddOrderNote(\sprintf('The returned %s came back.', self::listed($order, $return->items()))), $userId);
        });
    }

    /**
     * A change to a return, with its order locked and the return read again under the lock: two answers to it
     * sent at once are made one after the other, the second finding it answered.
     *
     * @param \Closure(ItemReturn, OrderView): void $change given the return and its order in the shop's words
     *
     * @param-immediately-invoked-callable $change
     *
     * @throws ReturnNotFound
     */
    private function changing(int $id, \Closure $change): ReturnView
    {
        $orderId = $this->get($id)->orderId();

        return $this->orders->whileLocked($orderId, function () use ($id, $orderId, $change): ReturnView {
            $return = $this->returns->find($id, true) ?? throw new ReturnNotFound($id);
            $order = $this->orders->order($orderId);
            $change($return, $order);

            return $this->returnView($order, $return);
        });
    }

    /**
     * Gives the money back for the items that came back, with their tax, through the payment provider when it
     * can; the items go back in stock.
     *
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return whose items did not come back
     * @throws RuleViolation       when the refund cannot be made, as the order says why
     */
    public function refund(int $id, RefundReturn $command, ?int $userId): ReturnView
    {
        $return = $this->get($id);
        $order = $this->orders->order($return->orderId());
        if ($return->status() !== ReturnStatus::Received) {
            throw new ReturnRuleViolation('Return %1$d is %2$s; its items are refunded once they came back.', 'orillacart_return_not_refundable', values: [$id, $return->status()->value]);
        }
        try {
            $refunds = $this->refunds->refund($order->number, new MakeRefund(
                (string) $this->amount($order, $return->items(), $return->id()),
                array_map(static fn(int $line, int $quantity): RefundLine => new RefundLine($line, $quantity), array_keys($return->items()), $return->items()),
                restock: $command->restock,
                reason: \sprintf('Return %d', $id),
                outside: $command->outside && !$command->storeCredit,
                storeCredit: $command->storeCredit,
            ), $userId, function (int $refundId) use ($id): void {
                // With the refund, under the order's lock, the return takes it as its own: a second refund of the
                // return sent at the same time waits, finds it refunding, and is refused, undoing its refund.
                $return = $this->returns->find($id, true) ?? throw new ReturnNotFound($id);
                $return->refunding($refundId);
                $this->returns->save($return);
            });
        } catch (PaymentRuleViolation $e) {
            // The provider refused it, or did not answer: the return follows the refund it took (settle()), back to
            // received when the refund failed, refunded once a waiting one goes, rather than staying received with
            // its lines refunded under it.
            $this->settle($order->number);

            throw $e;
        }
        $return = $this->get($id);
        $made = array_values(array_filter($refunds->refunds, static fn(RefundView $refund): bool => $refund->id === $return->refundId()));
        // Refunded once the money went: a provider that sends it later says so, and the return follows (settle()).
        if (($made[0] ?? throw new \LogicException("Return {$id}'s refund was not recorded."))->status === 'done') {
            $return->refunded($this->clock->now());
            $this->returns->save($return);
        }

        return $this->returnView($this->orders->order($order->number), $return);
    }

    /**
     * Returns whose refund the provider was sending, or did not answer: refunded once it went, back to received when
     * it failed or was given up, for the shop to refund again; left as they are while it waits.
     */
    public function settle(int $orderId): void
    {
        $refunding = array_filter($this->returns->forOrder($orderId), static fn(ItemReturn $return): bool => $return->status() === ReturnStatus::Refunding);
        if ($refunding === []) {
            return;
        }
        $refunds = [];
        foreach ($this->refunds->forOrder($orderId)->refunds as $refund) {
            $refunds[$refund->id] = $refund;
        }
        foreach ($refunding as $return) {
            $refund = $refunds[$return->refundId() ?? 0] ?? null;
            if ($refund?->status === 'done') {
                $return->refunded($refund->settledAt === null ? $this->clock->now() : new \DateTimeImmutable($refund->settledAt));
                $this->returns->save($return);
            } elseif ($refund === null || $refund->status === 'failed') {
                $return->refundFailed();
                $this->returns->save($return);
                $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The refund of return %d did not go through%s: refund it again.', $return->id(), $refund?->error === null ? '' : ' (' . $refund->error . ')')), null);
            }
        }
    }

    private function view(OrderView $order): OrderReturnsView
    {
        $this->settle($order->number);
        $until = $this->until($order);
        $left = $this->returnable($order);

        return new OrderReturnsView(
            $this->accepting($order) && $left !== [],
            $until?->format('Y-m-d'),
            array_map(static fn(int $line, int $quantity): ReturnedItemView => new ReturnedItemView($line, self::lineAt($order, $line)->name ?? '', $quantity), array_keys($left), $left),
            array_map(fn(ItemReturn $return): ReturnView => $this->returnView($order, $return), $this->returns->forOrder($order->number)),
        );
    }

    /** The last moment the customer may ask: the window's last day, in full. */
    private function until(OrderView $order): ?\DateTimeImmutable
    {
        $days = $this->settings->current()->returnDays;
        if ($days === null || $order->fulfilledAt === null) {
            return null;
        }

        return new \DateTimeImmutable($order->fulfilledAt)->modify("+{$days} days");
    }

    private function accepting(OrderView $order): bool
    {
        $until = $this->until($order);

        // Paid: an order paid on delivery that never was has no money to give back.
        return $until !== null && $order->paidAt !== null && !\in_array($order->status, self::CLOSED, true) && $this->clock->now() <= $until;
    }

    /**
     * What may still go back of each line that shipped: its items, less those refunded and those a return holds.
     *
     * @return array<int, int> quantities by line position, only those with some
     */
    private function returnable(OrderView $order): array
    {
        $left = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            if ($line->ships) {
                $left[$position] = $line->quantity - $line->refundedQuantity;
            }
        }
        foreach ($this->returns->forOrder($order->number) as $return) {
            if ($return->status()->holdsItems()) {
                foreach ($return->items() as $position => $quantity) {
                    $left[$position] = ($left[$position] ?? 0) - $quantity;
                }
            }
        }

        return array_filter($left, static fn(int $quantity): bool => $quantity > 0);
    }

    private function returnView(OrderView $order, ItemReturn $return): ReturnView
    {
        $items = $return->items();

        return new ReturnView(
            $return->id(),
            $return->status()->value,
            array_map(static fn(int $line, int $quantity): ReturnedItemView => new ReturnedItemView($line, self::lineAt($order, $line)->name ?? '', $quantity), array_keys($items), $items),
            $return->reason()->value,
            $return->reason()->label(),
            $return->comment(),
            $return->answer(),
            (string) $this->amount($order, $items, $return->id()),
            $return->requestedAt()->format(\DATE_ATOM),
            $return->answeredAt()?->format(\DATE_ATOM),
            $return->receivedAt()?->format(\DATE_ATOM),
            $return->refundedAt()?->format(\DATE_ATOM),
        );
    }

    /**
     * What the customer paid for these items: each line's total less its discount, with its tax unless the prices
     * include it, for the share of its items going back; in the currency's decimals.
     *
     * @param array<int, int> $items quantities by line position
     */
    private function amount(OrderView $order, array $items, ?int $returnId = null): Money
    {
        $decimals = new Currency($order->currency)->decimals();
        $amount = Money::zero();
        $policy = $this->settings->current()->freeItemsOnReturn;
        // The items of a deal that gave free ones refund their share of what its items cost together.
        $shares = $policy === FreeItemsOnReturn::Spread->value ? $this->shares($order) : [];
        if ($policy === FreeItemsOnReturn::Charged->value) {
            // A free item what is kept no longer earns is the customer's at its price: kept from what goes back.
            foreach ($this->unearned($order, $items, $returnId) as $position => $units) {
                $line = $order->lines[$position];
                $percent = array_reduce($line->taxes, static fn(Money $sum, LineTaxView $tax): Money => $sum->add(Money::of($tax->percent)), Money::zero());
                $price = Money::of($line->unitPrice)->times($units);
                $amount = $amount->subtract($order->pricesIncludeTax ? $price : $price->add($price->percent((string) $percent)));
            }
        }
        foreach ($items as $position => $quantity) {
            $line = self::lineAt($order, $position);
            if ($line === null || $line->quantity < 1) {
                continue;
            }
            if (isset($shares[$position])) {
                $amount = $amount->add($shares[$position]->times($quantity));
                continue;
            }
            $amount = $amount->add(self::paid($order, $line)->proportion(Money::of((string) $quantity), Money::of((string) $line->quantity)));
        }

        return ($amount->isNegative() ? Money::zero() : $amount)->rounded($decimals);
    }

    /** The order's item or charge at the place, as refunds name them: the items first, at their places, then the charges. */
    private static function lineAt(OrderView $order, int $position): ?OrderLineView
    {
        return $order->lines[$position] ?? array_find($order->charges, static fn(OrderLineView $line): bool => $line->type === 'charge' && $line->position === $position);
    }

    /**
     * The items going back with the charges for them: of a charge for some products (a deposit for the bottles),
     * the share of it those of them that went back, go back and are going back come to, less what of it did.
     *
     * @param array<int, int> $items quantities by line position, going back now
     *
     * @return array<int, int> with the charges' places, in order
     */
    private function withCharges(OrderView $order, array $items): array
    {
        $back = $items;
        foreach ($this->returns->forOrder($order->number) as $other) {
            if ($other->status()->holdsItems()) {
                foreach ($other->items() as $position => $quantity) {
                    $back[$position] = ($back[$position] ?? 0) + $quantity;
                }
            }
        }
        foreach ($order->charges as $charge) {
            $for = $charge->charge['for_product_ids'] ?? [];
            if ($charge->type !== 'charge' || $for === []) {
                continue;
            }
            $of = 0;
            $gone = 0;
            foreach ($order->lines as $position => $line) {
                if (\in_array($line->productId, $for, true)) {
                    $of += $line->quantity;
                    $gone += $line->refundedQuantity + ($back[$position] ?? 0);
                }
            }
            $owed = Charge::share(min($gone, $of), $of, $charge->quantity) - $charge->refundedQuantity - ($back[$charge->position] ?? 0);
            if ($owed > 0) {
                $items[$charge->position] = ($items[$charge->position] ?? 0) + $owed;
            }
        }
        ksort($items);

        return $items;
    }

    /** What the customer paid for the line: its total less its discount, with its tax unless the prices include it. */
    private static function paid(OrderView $order, OrderLineView $line): Money
    {
        return Money::of($line->total)->subtract(Money::of($line->discount))->add($order->pricesIncludeTax ? Money::zero() : Money::of($line->tax));
    }

    /**
     * Each item's share of a deal that gave free items, by line position: what its items (those it gave, and those
     * it applies to) cost together, over how many they are. A line in two deals takes the first's.
     *
     * @return array<int, Money>
     */
    private function shares(OrderView $order): array
    {
        $deals = [];
        foreach ($order->lines as $position => $line) {
            if ($line->givenBy !== null) {
                $deals[$line->givenBy['code']] = true;
            }
        }
        $shares = [];
        foreach (array_keys($deals) as $code) {
            $code = (string) $code;
            $items = array_filter($order->lines, fn(OrderLineView $line): bool => ($line->givenBy['code'] ?? null) === $code || ($line->givenBy === null && $line->addOn === null && $this->earned->appliesTo($code, $line->productId)));
            $units = array_sum(array_map(static fn(OrderLineView $line): int => $line->quantity, $items));
            if ($units < 1) {
                continue;
            }
            $cost = array_reduce($items, static fn(Money $sum, OrderLineView $line): Money => $sum->add(self::paid($order, $line)), Money::zero());
            $share = $cost->proportion(Money::of('1'), Money::of((string) $units));
            foreach (array_keys($items) as $position) {
                $shares[$position] ??= $share;
            }
        }

        return $shares;
    }

    /**
     * The free items the customer would keep that what they keep no longer earns, once these items go back with
     * those already refunded or held by other returns: by the given line's position. None for a coupon gone since.
     *
     * @param array<int, int> $items quantities by line position, going back now
     * @param int|null        $returnId the return they are, left out of the others
     *
     * @return array<int, int>
     */
    private function unearned(OrderView $order, array $items, ?int $returnId = null): array
    {
        $back = $items;
        foreach ($this->returns->forOrder($order->number) as $other) {
            if ($other->id() !== $returnId && $other->status()->holdsItems()) {
                foreach ($other->items() as $position => $quantity) {
                    $back[$position] = ($back[$position] ?? 0) + $quantity;
                }
            }
        }
        $kept = [];
        $given = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            $left = max(0, $line->quantity - $line->refundedQuantity - ($back[$position] ?? 0));
            if ($line->givenBy !== null) {
                $given[$line->givenBy['code']][$position] = $left;
            } else {
                $kept[] = ['product_id' => $line->productId, 'quantity' => $left, 'total' => (string) Money::of($line->total)->proportion(Money::of((string) $left), Money::of((string) max(1, $line->quantity)))];
            }
        }
        $unearned = [];
        foreach ($given as $code => $lines) {
            $earned = $this->earned->earned((string) $code, $kept, $order->currency);
            if ($earned === null) {
                continue;
            }
            // The last given go first.
            $over = array_sum($lines) - $earned;
            foreach (array_reverse($lines, true) as $position => $left) {
                $units = min($over, $left);
                if ($units > 0) {
                    $unearned[$position] = $units;
                    $over -= $units;
                }
            }
        }
        ksort($unearned);

        return $unearned;
    }

    /**
     * "2 × Lamp, 1 × Shade".
     *
     * @param array<int, int> $items quantities by line position
     */
    private static function listed(OrderView $order, array $items): string
    {
        return implode(', ', array_map(static fn(int $line, int $quantity): string => \sprintf('%d × %s', $quantity, self::lineAt($order, $line)->name ?? (string) ($line + 1)), array_keys($items), $items));
    }

    private function get(int $id): ItemReturn
    {
        return $this->returns->find($id) ?? throw new ReturnNotFound($id);
    }
}
