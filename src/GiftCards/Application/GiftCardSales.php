<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application;

use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\GiftCards\Domain\GiftCardCode;
use OrillaCart\GiftCards\Domain\GiftCardEntry;
use OrillaCart\GiftCards\Domain\GiftCardRepository;
use OrillaCart\GiftCards\Domain\GiftCardSettingsRepository;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use Psr\Clock\ClockInterface;

/**
 * Gift cards bought in the shop: once an order is paid, each item of a product sold as a gift card becomes a card
 * worth what the customer paid for it, sent to the customer to give on. Once per order.
 */
final readonly class GiftCardSales
{
    public function __construct(
        private GiftCardRepository $cards,
        private GiftCardSettingsRepository $settings,
        private OrderService $orders,
        private GiftCardMail $mail,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {}

    /**
     * @return int how many cards the order made
     *
     * @throws OrderNotFound
     */
    public function issueFor(int $orderId): int
    {
        $settings = $this->settings->load();
        if ($settings->productIds === [] || $this->cards->boughtWith($orderId) !== []) {
            return 0;
        }
        $order = $this->orders->order($orderId);
        $now = $this->clock->now();
        $made = [];
        foreach ($order->lines as $line) {
            if (!$settings->sellsAsGiftCard($line->productId) || $line->quantity < 1) {
                continue;
            }
            $paid = Money::of($line->total)->subtract(Money::of($line->discount))->add($order->pricesIncludeTax ? Money::zero() : Money::of($line->tax));
            // In the currency's cents, the last card taking what is left: three cards of 10.00 are 3.33, 3.33, 3.34.
            $each = $paid->proportion(Money::of('1'), Money::of((string) $line->quantity))->rounded(new Currency($order->currency)->decimals());
            if ($each->isZero() || $each->isNegative()) {
                continue;
            }
            for ($i = 0; $i < $line->quantity; ++$i) {
                $value = $i === $line->quantity - 1 ? $paid->subtract($each->times($line->quantity - 1)) : $each;
                $code = GiftCardCode::generate();
                $made[] = [$code, GiftCard::issue($code, $value, $order->currency, $now, $settings->expiry($now), $order->billing->firstName, $order->email, '', $orderId)];
            }
        }
        if ($made === []) {
            return 0;
        }
        $this->transactions->run(function () use ($made, $now, $orderId): void {
            foreach ($made as [, $card]) {
                $this->cards->save($card);
                $this->cards->add(new GiftCardEntry($card->id(), $card->initial(), \sprintf('Bought with order #%d', $orderId), $now, $orderId));
            }
        });
        $unsent = [];
        foreach ($made as [$code, $card]) {
            if (!$this->mail->send($card->recipientEmail(), (string) $code, (string) $card->initial(), $card->currency(), $card->expiresAt()?->format('Y-m-d'), $card->recipientName(), '', $order->locale)) {
                $unsent[] = '····' . $card->codeEnd();
            }
        }
        if ($unsent === []) {
            $this->orders->note($orderId, \sprintf('%d gift card(s) made and sent to %s.', \count($made), $order->email), false);
        } else {
            // Only the code's hash is kept: a card whose email did not go is one nobody can spend yet.
            $this->orders->needsAttention($orderId, \sprintf('%d gift card(s) made, but the email of %s to %s did not go (the gift card email is off, or the mail failed): give each a new code on the Gift cards screen to send it.', \count($made), implode(', ', $unsent), $order->email));
        }

        return \count($made);
    }

    /**
     * Money went back for an order that bought gift cards: as many of its cards as were refunded (all of them
     * when the whole order was) pay no more, those with the most left first. What a card had spent already is the
     * shop's to settle with the customer, and the order says so.
     *
     * @param array<int, int> $quantities items refunded by line position
     *
     * @throws OrderNotFound
     */
    public function refunded(int $orderId, array $quantities, bool $full): void
    {
        $cards = $this->cards->boughtWith($orderId);
        if ($cards === []) {
            return;
        }
        $order = $this->orders->order($orderId);
        $settings = $this->settings->load();
        $count = $full ? \count($cards) : 0;
        foreach ($full ? [] : $quantities as $position => $quantity) {
            $line = $order->lines[$position] ?? null;
            if ($line !== null && $settings->sellsAsGiftCard($line->productId)) {
                $count += $quantity;
            }
        }
        if ($count < 1) {
            return;
        }
        $now = $this->clock->now();
        $spent = $this->transactions->run(function () use ($cards, $count, $orderId, $now): array {
            $locked = array_values(array_filter(array_map(fn(GiftCard $card): ?GiftCard => $this->cards->find($card->id(), true), $cards), static fn(?GiftCard $card): bool => $card !== null && $card->isActive()));
            usort($locked, static fn(GiftCard $a, GiftCard $b): int => $b->balance()->compare($a->balance()) !== 0 ? $b->balance()->compare($a->balance()) : $a->id() <=> $b->id());
            $spent = [];
            foreach (\array_slice($locked, 0, $count) as $card) {
                $left = $card->spend($card->balance());
                $card->change(false, $card->expiresAt());
                $this->cards->save($card);
                if (!$left->isZero()) {
                    $this->cards->add(new GiftCardEntry($card->id(), Money::zero()->subtract($left), \sprintf('Order #%d refunded', $orderId), $now, $orderId));
                }
                if (!$card->initial()->subtract($left)->isZero()) {
                    $spent[] = \sprintf('····%s had spent %s of %s', $card->codeEnd(), $card->initial()->subtract($left), $card->initial());
                }
            }

            return $spent;
        });
        $this->orders->note($orderId, \sprintf('%d gift card(s) bought with the order pay no more, as it was refunded.', min($count, \count($cards))) . ($spent === [] ? '' : ' ' . implode('; ', $spent) . \sprintf(' %s: settle that with the customer.', $order->currency)), false);
    }
}
