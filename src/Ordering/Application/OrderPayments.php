<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Inventory\Application\Dto\StockNote;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Inventory\Domain\StockReason;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Domain\Event\OrderChanged;
use OrillaCart\Ordering\Domain\Event\OrderMarkingPaid;
use OrillaCart\Ordering\Domain\Event\OrderNeedsAttention;
use OrillaCart\Ordering\Domain\NoteAuthor;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\TakenItem;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * An order's money: waiting for it, its coming (from a provider, the shop, gift cards, or after the order closed),
 * its failing, and the orders cancelled because it never came.
 */
final readonly class OrderPayments
{
    public function __construct(
        private OrderChanges $changes,
        private OrderRepository $orders,
        private StockService $stock,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {}

    /**
     * The shop waits for the money: an offline payment, or one the provider holds for review.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that is no longer open
     */
    public function hold(int $id, string $note = '', bool $tellCustomer = true, bool $tellShop = true): void
    {
        $this->changes->changing($id, function (Order $order) use ($note, $tellCustomer, $tellShop): void {
            $previous = $order->status();
            if ($order->hold()) {
                $this->changes->changed($order, $previous, [], $note, tellCustomer: $tellCustomer, tellShop: $tellShop);
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
        $order = $this->changes->get($id);
        $order->payWithGiftCards(array_map(static fn(array $card): array => ['label' => $card['label'], 'amount' => Money::of($card['amount'])], $cards));
        $this->orders->save($order);
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order that was cancelled or failed before the money came
     */
    public function markPaid(int $id, ?string $transactionId, string $note = '', bool $tellCustomer = true, bool $tellShop = true, ?string $amount = null): void
    {
        $this->changes->changing($id, function (Order $order) use ($transactionId, $note, $tellCustomer, $tellShop, $amount): void {
            $previous = $order->status();
            if ($order->markPaid($transactionId, $this->clock->now(), $amount === null ? null : Money::of($amount))) {
                $this->paidOtherThanTotal($order);
                $order->status() === $previous ? $this->paidWhileFulfilled($order, $note, null) : $this->changes->changed($order, $previous, [], $note, tellCustomer: $tellCustomer, tellShop: $tellShop);
            } elseif ($transactionId !== null && $transactionId !== $order->transactionId()) {
                $again = $order->paidAgain($transactionId);
                $text = $again
                    ? \sprintf('Another payment came for this order, which was already paid (transaction %s): check whether to give one back.', $transactionId)
                    : \sprintf('The payment came for this order, which was already marked paid. %s', trim($note));
                $order->addNote(trim($text), NoteAuthor::System, false, $this->clock->now());
                if ($again) {
                    // Paid twice, as by an earlier payment the customer left open and finished after paying again.
                    $order->needsAttention($text, $this->clock->now());
                }
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
        return $this->changes->changing($id, function (Order $order) use ($note, $userId): OrderView {
            $previous = $order->status();
            if ($order->paidAt() !== null) {
                throw new OrderRuleViolation('Order #%d is paid already.', 'orillacart_order_paid', values: [$order->id()]);
            }
            $marking = new OrderMarkingPaid($order->id());
            $this->events->dispatch($marking);
            if (!$order->markPaid($marking->transactionId ?? $order->transactionId(), $this->clock->now())) {
                throw new OrderRuleViolation('Order #%d is paid already.', 'orillacart_order_paid', values: [$order->id()]);
            }
            $order->status() === $previous ? $this->paidWhileFulfilled($order, $note, $userId) : $this->changes->changed($order, $previous, [], $note, $userId);

            return $this->changes->view($order, true);
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
            $this->changes->changing($id, function (Order $order) use ($transactionId, $note, $amount): void {
                $previous = $order->status();
                $order->reopenPaid($transactionId, $this->clock->now(), fn(array $wanted): array => array_map(
                    static fn(array $taken): array => array_map(static fn(TakenStock $part): TakenItem => new TakenItem($part->item, $part->stockroomId, $part->quantity), $taken),
                    $this->stock->takeAgain($wanted, new StockNote(StockReason::Sale, $order->id())),
                ), $amount === null ? null : Money::of($amount));
                $this->paidOtherThanTotal($order);
                $this->changes->changed($order, $previous, [], trim(\sprintf('The money came after the order was %s, so it was opened again and its items taken again. %s', strtolower($previous->label()), $note)));
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
    /**
     * @param string|null $shopName the way to pay in the shop's own language, where the customer's differs
     */
    public function awaitPayment(int $id, string $gateway, string $name, string $note, ?string $shopName = null): OrderView
    {
        return $this->changes->changing($id, function (Order $order) use ($gateway, $name, $note, $shopName): OrderView {
            $previous = $order->status();
            $order->awaitPayment($gateway, $name, $note, $this->clock->now(), fn(array $wanted): array => array_map(
                static fn(array $taken): array => array_map(static fn(TakenStock $part): TakenItem => new TakenItem($part->item, $part->stockroomId, $part->quantity), $taken),
                $this->stock->takeAgain($wanted, new StockNote(StockReason::Sale, $order->id())),
            ), $shopName);
            $text = \sprintf('The customer chose to pay by %s.', $order->shopPaymentName());
            if ($order->status() !== $previous) {
                $this->changes->changed($order, $previous, [], $text);
            } else {
                $order->addNote($text, NoteAuthor::System, false, $this->clock->now());
                $this->orders->save($order);
                $this->events->dispatch(new OrderChanged($order->id()));
            }

            return $this->changes->view($order);
        });
    }

    /**
     * The gift cards that paid part of an order opened again no longer had all of it: the order asks the
     * customer's way to pay for that share, counts as paid only what came, and the shop looks at it.
     *
     * @throws OrderNotFound
     */
    public function giftCardsFellShort(int $id, Money $short): void
    {
        $this->changes->changing($id, function (Order $order) use ($short): void {
            $reason = \sprintf('The gift cards that paid this order had spent %s %s of it since it was closed: that share is now left to pay.', $short, $order->currency());
            if ($order->giftCardsFellShort($short, $reason, $this->clock->now())->isZero()) {
                return;
            }
            $order->addNote($reason, NoteAuthor::System, false, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(new OrderNeedsAttention($order->id(), $reason));
        });
    }

    /**
     * @throws OrderNotFound
     */
    public function fail(int $id, string $note = ''): void
    {
        $this->changes->changing($id, function (Order $order) use ($note): void {
            $previous = $order->status();
            $this->changes->changed($order, $previous, $order->fail(), $note);
        });
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
        return $this->changes->changing($id, function (Order $order) use ($days): bool {
            if ($order->status() !== OrderStatus::OnHold || $order->paidAt() !== null) {
                return false;
            }
            $this->changes->changed($order, OrderStatus::OnHold, $order->cancel(), \sprintf('The payment did not come within %d days.', $days));

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
        foreach ($this->orders->pendingPaymentSince($this->clock->now()->sub(new \DateInterval(Order::PAYMENT_WINDOW))) as $found) {
            $cancelled += $this->changes->changing($found->id(), function (Order $order): int {
                // Looked at again under the lock: the payment may have come in since the list was read.
                if ($order->status() !== OrderStatus::PendingPayment) {
                    return 0;
                }
                $this->changes->changed($order, OrderStatus::PendingPayment, $order->cancel(), 'The payment was not finished within three hours.');

                return 1;
            });
        }

        return $cancelled;
    }
}
