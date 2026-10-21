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
            $each = $paid->proportion(Money::of('1'), Money::of((string) $line->quantity));
            if ($each->isZero() || $each->isNegative()) {
                continue;
            }
            for ($i = 0; $i < $line->quantity; ++$i) {
                $code = GiftCardCode::generate();
                $made[] = [$code, GiftCard::issue($code, $each, $order->currency, $now, $settings->expiry($now), $order->billing->firstName, $order->email, '', $orderId)];
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
        foreach ($made as [$code, $card]) {
            $this->mail->send($card->recipientEmail(), (string) $code, (string) $card->initial(), $card->currency(), $card->expiresAt()?->format('Y-m-d'), $card->recipientName(), '');
        }
        $this->orders->note($orderId, \sprintf('%d gift card(s) made and sent to %s.', \count($made), $order->email), false);

        return \count($made);
    }
}
