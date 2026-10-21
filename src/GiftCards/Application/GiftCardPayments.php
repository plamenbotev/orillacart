<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application;

use OrillaCart\GiftCards\Application\Dto\GiftCardBalanceView;
use OrillaCart\GiftCards\Application\Dto\GiftCardPlan;
use OrillaCart\GiftCards\Application\Dto\GiftCardUse;
use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\GiftCards\Domain\GiftCardCode;
use OrillaCart\GiftCards\Domain\GiftCardEntry;
use OrillaCart\GiftCards\Domain\GiftCardRepository;
use OrillaCart\GiftCards\Domain\GiftCardRuleViolation;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Gift cards and store credit paying for orders: what they would pay of an order, taking it when the order is
 * placed, under a lock on each card, and giving it back when the order does not go ahead.
 */
final readonly class GiftCardPayments
{
    /** Codes an order takes at most. */
    public const int MAX_CODES = 5;

    public function __construct(
        private GiftCardRepository $cards,
        private OrderService $orders,
        private ClockInterface $clock,
    ) {}

    /**
     * What a code has left, for the customer who typed it.
     *
     * @throws GiftCardRuleViolation for a code that is no card's
     */
    public function balance(string $typed, string $currency): GiftCardBalanceView
    {
        $card = $this->withCode($typed) ?? throw new GiftCardRuleViolation('This gift card is not valid.', 'orillacart_gift_card_refused');

        return new GiftCardBalanceView($card->label(), (string) $card->balance(), $card->currency(), $card->expiresAt()?->format(\DATE_ATOM), $card->refusal($currency, $this->clock->now()));
    }

    /**
     * How the codes, and the customer's store credit when they use it, would pay a total: each in turn takes what
     * is left, store credit last.
     *
     * @param list<string> $codes as entered
     */
    public function plan(array $codes, ?int $customerId, bool $useCredit, Money $total, string $currency): GiftCardPlan
    {
        $now = $this->clock->now();
        $left = $total;
        $uses = [];
        $refused = [];
        $seen = [];
        foreach (\array_slice($codes, 0, self::MAX_CODES) as $typed) {
            $card = $this->withCode($typed);
            $refusal = $card === null ? 'This gift card is not valid.' : $card->refusal($currency, $now);
            if ($card === null || $refusal !== null) {
                $refused[$typed] = $refusal ?? 'This gift card is not valid.';
                continue;
            }
            if (isset($seen[$card->id()])) {
                continue;
            }
            $seen[$card->id()] = true;
            $amount = self::least($left, $card->balance());
            $uses[] = new GiftCardUse($card->id(), $typed, $card->label(), (string) $card->balance(), (string) $amount);
            $left = $left->subtract($amount);
        }
        $credit = $customerId === null ? null : $this->cards->storeCreditOf($customerId);
        $available = $credit === null || $credit->refusal($currency, $now) !== null ? Money::zero() : $credit->balance();
        if ($credit !== null && $useCredit && !$available->isZero() && !$left->isZero()) {
            $uses[] = new GiftCardUse($credit->id(), null, $credit->label(), (string) $available, (string) self::least($left, $available));
        }

        return new GiftCardPlan($uses, $refused, (string) $available);
    }

    /**
     * Takes what the plan said from each card, for the order, and records it on the order. Run it in the
     * transaction that places the order.
     *
     * @throws GiftCardRuleViolation when a card no longer has it: another order spent it in the meantime
     */
    public function pay(int $orderId, GiftCardPlan $plan, string $currency): void
    {
        $paid = [];
        $now = $this->clock->now();
        foreach ($plan->uses as $use) {
            $amount = Money::of($use->amount);
            if ($amount->isZero()) {
                continue;
            }
            $card = $this->cards->find($use->cardId, true);
            if ($card === null || $card->refusal($currency, $now) !== null || $card->balance()->compare($amount) < 0) {
                throw new GiftCardRuleViolation(\sprintf('What is left on %s changed. Review your order and place it again.', $use->label), 'orillacart_gift_card_changed');
            }
            $card->spend($amount);
            $this->cards->save($card);
            $this->cards->add(new GiftCardEntry($card->id(), Money::zero()->subtract($amount), \sprintf('Paid for order #%d', $orderId), $now, $orderId));
            $paid[] = ['label' => $card->label(), 'amount' => (string) $amount];
        }
        if ($paid !== []) {
            $this->orders->payWithGiftCards($orderId, $paid);
        }
    }

    /**
     * The order did not go ahead (cancelled, or its payment failed): each card gets back what it paid, once.
     */
    public function giveBack(int $orderId): void
    {
        foreach ($this->net($orderId) as $cardId => ['net' => $net]) {
            if (!$net->isNegative()) {
                continue;
            }
            $card = $this->cards->find($cardId, true);
            if ($card === null) {
                continue;
            }
            $back = Money::zero()->subtract($net);
            $card->credit($back);
            $this->cards->save($card);
            $this->cards->add(new GiftCardEntry($cardId, $back, \sprintf('Given back: order #%d did not go ahead', $orderId), $this->clock->now(), $orderId));
        }
    }

    /**
     * An order that did not go ahead was paid after all and opened again: each card pays again what it gave back,
     * as far as it still has it.
     *
     * @return Money what the cards no longer had, for the shop to settle with the customer
     */
    public function reclaim(int $orderId): Money
    {
        $short = Money::zero();
        foreach ($this->net($orderId) as $cardId => ['net' => $net, 'spent' => $spent]) {
            if (!$net->isZero() || $spent->isZero()) {
                continue;
            }
            $card = $this->cards->find($cardId, true);
            if ($card === null) {
                $short = $short->add($spent);
                continue;
            }
            $taken = $card->spend($spent);
            $short = $short->add($spent->subtract($taken));
            $this->cards->save($card);
            if (!$taken->isZero()) {
                $this->cards->add(new GiftCardEntry($cardId, Money::zero()->subtract($taken), \sprintf('Paid again for order #%d, opened again', $orderId), $this->clock->now(), $orderId));
            }
        }

        return $short;
    }

    /**
     * What the order's entries come to for each card, and what it spent of it at first.
     *
     * @return array<int, array{net: Money, spent: Money}>
     */
    private function net(int $orderId): array
    {
        $net = [];
        foreach ($this->cards->entriesOfOrder($orderId) as $entry) {
            $net[$entry->cardId()] ??= ['net' => Money::zero(), 'spent' => Money::zero(), 'first' => true];
            $net[$entry->cardId()]['net'] = $net[$entry->cardId()]['net']->add($entry->amount());
            if ($net[$entry->cardId()]['first'] && $entry->amount()->isNegative()) {
                $net[$entry->cardId()]['spent'] = Money::zero()->subtract($entry->amount());
                $net[$entry->cardId()]['first'] = false;
            }
        }

        return array_map(static fn(array $card): array => ['net' => $card['net'], 'spent' => $card['spent']], $net);
    }

    private function withCode(string $typed): ?GiftCard
    {
        $code = GiftCardCode::typed($typed);

        return $code === null ? null : $this->cards->withCode($code);
    }

    private static function least(Money $a, Money $b): Money
    {
        return $a->compare($b) <= 0 ? $a : $b;
    }
}
