<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\OrderReturnsView;
use OrillaCart\Returns\Application\Dto\RefundReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnedItemView;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Application\Dto\ReturnView;
use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnNotFound;
use OrillaCart\Returns\Domain\ReturnReason;
use OrillaCart\Returns\Domain\ReturnRepository;
use OrillaCart\Returns\Domain\ReturnRuleViolation;
use OrillaCart\Returns\Domain\ReturnStatus;
use OrillaCart\Settings\Application\SettingsService;
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
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function forOrder(int $orderId): OrderReturnsView
    {
        return $this->view($this->orders->order($orderId));
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
        return $this->orders->whileLocked($order->number, fn(): ReturnView => $this->requested($this->orders->order($order->number), $command));
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
                throw new ReturnRuleViolation('%1$d of "%2$s" can go back.', 'orillacart_invalid_return_items', values: [$left[$line] ?? 0, $order->lines[$line]->name ?? (string) ($line + 1)]);
            }
        }
        ksort($items);
        $return = new ItemReturn($order->number, $items, ReturnReason::from($command->reason), $command->comment, $this->clock->now());
        $this->returns->save($return);
        $this->orders->needsAttention($order->number, \sprintf('The customer asks to return %s: %s.%s', self::listed($order, $items), strtolower((string) $return->reason()->label()), $return->comment() === '' ? '' : ' "' . $return->comment() . '"'));

        return $this->returnView($order, $return);
    }

    /**
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return answered already
     */
    public function approve(int $id, AnswerReturn $command, ?int $userId): ReturnView
    {
        $return = $this->get($id);
        $return->approve($command->answer, $this->clock->now());
        $this->returns->save($return);
        $order = $this->orders->order($return->orderId());
        $this->orders->addNote($order->number, new AddOrderNote(trim($this->translations->text(Text::of('Your return of %1$s is approved. %2$s', self::listed($order, $return->items()), $return->answer()))), true), $userId);

        return $this->returnView($order, $return);
    }

    /**
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return answered already, or no reason given
     */
    public function refuse(int $id, AnswerReturn $command, ?int $userId): ReturnView
    {
        $return = $this->get($id);
        $return->refuse($command->answer, $this->clock->now());
        $this->returns->save($return);
        $order = $this->orders->order($return->orderId());
        $this->orders->addNote($order->number, new AddOrderNote($this->translations->text(Text::of('Your return of %1$s is refused: %2$s', self::listed($order, $return->items()), $return->answer())), true), $userId);

        return $this->returnView($order, $return);
    }

    /**
     * @throws ReturnNotFound
     * @throws ReturnRuleViolation for a return refused, or received already
     */
    public function receive(int $id, ?int $userId): ReturnView
    {
        $return = $this->get($id);
        $return->receive($this->clock->now());
        $this->returns->save($return);
        $order = $this->orders->order($return->orderId());
        $this->orders->addNote($order->number, new AddOrderNote(\sprintf('The returned %s came back.', self::listed($order, $return->items()))), $userId);

        return $this->returnView($order, $return);
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
        $refunds = $this->refunds->refund($order->number, new MakeRefund(
            (string) $this->amount($order, $return->items()),
            array_map(static fn(int $line, int $quantity): RefundLine => new RefundLine($line, $quantity), array_keys($return->items()), $return->items()),
            restock: $command->restock,
            reason: \sprintf('Return %d', $id),
            outside: $command->outside && !$command->storeCredit,
            storeCredit: $command->storeCredit,
        ), $userId);
        $made = array_values(array_filter($refunds->refunds, static fn(RefundView $refund): bool => $refund->reason === \sprintf('Return %d', $id)));
        usort($made, static fn(RefundView $a, RefundView $b): int => $b->id <=> $a->id);
        // Refunded once the money went: a provider that sends it later says so, and the return follows (settle()).
        if (($made[0] ?? null)?->status === 'done') {
            $return->refunded($this->clock->now());
        } else {
            $return->refunding($made[0]->id ?? throw new \LogicException("Return {$id}'s refund was not recorded."));
        }
        $this->returns->save($return);

        return $this->returnView($this->orders->order($order->number), $return);
    }

    /**
     * Returns whose refund the provider was sending: refunded once it did, back to received when it gave up, for
     * the shop to refund again.
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
            array_map(static fn(int $line, int $quantity): ReturnedItemView => new ReturnedItemView($line, $order->lines[$line]->name ?? '', $quantity), array_keys($left), $left),
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
            array_map(static fn(int $line, int $quantity): ReturnedItemView => new ReturnedItemView($line, $order->lines[$line]->name ?? '', $quantity), array_keys($items), $items),
            $return->reason()->value,
            $return->reason()->label(),
            $return->comment(),
            $return->answer(),
            (string) $this->amount($order, $items),
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
    private function amount(OrderView $order, array $items): Money
    {
        $decimals = new Currency($order->currency)->decimals();
        $amount = Money::zero();
        foreach ($items as $position => $quantity) {
            $line = $order->lines[$position] ?? null;
            if (!$line instanceof OrderLineView || $line->quantity < 1) {
                continue;
            }
            $gross = Money::of($line->total)->subtract(Money::of($line->discount))->add($order->pricesIncludeTax ? Money::zero() : Money::of($line->tax));
            $amount = $amount->add($gross->proportion(Money::of((string) $quantity), Money::of((string) $line->quantity)));
        }

        return $amount->rounded($decimals);
    }

    /**
     * "2 × Lamp, 1 × Shade".
     *
     * @param array<int, int> $items quantities by line position
     */
    private static function listed(OrderView $order, array $items): string
    {
        return implode(', ', array_map(static fn(int $line, int $quantity): string => \sprintf('%d × %s', $quantity, $order->lines[$line]->name ?? (string) ($line + 1)), array_keys($items), $items));
    }

    private function get(int $id): ItemReturn
    {
        return $this->returns->find($id) ?? throw new ReturnNotFound($id);
    }
}
