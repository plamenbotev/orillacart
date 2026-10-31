<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application;

use OrillaCart\Cart\Application\Dto\AbandonedCartView;
use OrillaCart\Cart\Application\Dto\CartReminder;
use OrillaCart\Cart\Domain\AbandonedCart;
use OrillaCart\Cart\Domain\AbandonedCartRepository;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Settings\Application\SettingsService;
use Psr\Clock\ClockInterface;

/**
 * Carts left at checkout. A cart nobody changed, and whose customer was not back at checkout, for the shop's hours
 * counts abandoned once: what it held is kept for the report, and a customer who asked at checkout is reminded of
 * it. An order placed from it after stops the reminder, and counts it recovered once paid. The email goes when the cart does.
 */
final readonly class AbandonedCarts
{
    /** Carts counted in one go; the next run takes the rest. */
    private const int BATCH = 200;

    public function __construct(
        private CartRepository $carts,
        private AbandonedCartRepository $abandoned,
        private CartService $cartService,
        private SettingsService $settings,
        private ClockInterface $clock,
    ) {}

    /**
     * Counts the carts left at checkout before the moment abandoned.
     *
     * @return list<int> the abandoned carts whose customers asked to be reminded
     */
    public function mark(\DateTimeImmutable $before): array
    {
        $now = $this->clock->now();
        $currency = $this->settings->current()->currency;
        $remind = [];
        foreach ($this->abandoned->leftSince($before, self::BATCH) as $cart) {
            $view = $this->cartService->viewOf($cart->id());
            $email = $cart->remind() ? $cart->contactEmail() : null;
            $left = new AbandonedCart($cart->id(), $cart->customerId(), $email, $view->items ?? 0, $view->subtotal ?? '0', $currency, $now);
            $this->abandoned->save($left);
            $cart->abandon($left->id());
            $this->carts->save($cart);
            if ($email !== null) {
                $remind[] = $left->id();
            }
        }

        return $remind;
    }

    /**
     * What to remind the customer of; null when there is nothing to: the cart was ordered, emptied, is gone, its
     * customer no longer asks, or was reminded. Without the link to bring a visitor's cart back: that comes once
     * the reminder is claimed (withLink), so a second worker does not undo the link of the one sending it.
     */
    public function reminder(int $abandonedId): ?CartReminder
    {
        $left = $this->abandoned->find($abandonedId);
        if ($left === null || $left->isOrdered() || $left->remindedAt() !== null) {
            return null;
        }
        $cart = $this->carts->find($left->cartId());
        $email = $cart?->contactEmail();
        if ($cart === null || $cart->abandonedId() !== $abandonedId || $cart->isEmpty() || !$cart->remind() || $email === null) {
            return null;
        }
        $view = $this->cartService->viewOf($cart->id());
        if ($view === null) {
            return null;
        }
        return new CartReminder($abandonedId, $email, $cart->locale(), $cart->customerId(), $view, null);
    }

    /**
     * The claimed reminder of a visitor's cart, with a new link that brings it back; a customer's as it is.
     */
    public function withLink(CartReminder $reminder): CartReminder
    {
        $left = $reminder->customerId === null ? $this->abandoned->find($reminder->abandonedId) : null;
        $cart = $left === null ? null : $this->carts->find($left->cartId());
        if ($cart === null) {
            return $reminder;
        }
        $token = bin2hex(random_bytes(32));
        $cart->recoverWith($token);
        $this->carts->save($cart);

        return new CartReminder($reminder->abandonedId, $reminder->email, $reminder->locale, $reminder->customerId, $reminder->cart, $token);
    }

    /**
     * Takes the reminder to send it: false when another worker has, or it went already.
     */
    public function claimReminder(int $abandonedId): bool
    {
        return $this->abandoned->claimReminder($abandonedId, $this->clock->now());
    }

    /** A reminder that did not go out is sent again later. */
    public function releaseReminder(int $abandonedId): void
    {
        $this->abandoned->releaseReminder($abandonedId);
    }

    /**
     * What was left between the moments, in the currency, and the latest of them.
     *
     * @return array{totals: array{abandoned: int, value: string, reminded: int, recovered: int, recovered_value: string, recovered_after_reminder: int}, latest: list<AbandonedCartView>}
     */
    public function report(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency, int $limit): array
    {
        return [
            'totals' => $this->abandoned->totals($from, $until, $currency),
            'latest' => array_map(AbandonedCartView::of(...), $this->abandoned->latest($from, $until, $currency, $limit)),
        ];
    }

    /** The order placed from a cart left at checkout was paid: the cart counts recovered from now. */
    public function orderPaid(int $orderId): void
    {
        $left = $this->abandoned->ofOrder($orderId);
        if ($left !== null && !$left->isRecovered()) {
            $left->recovered($this->clock->now());
            $this->abandoned->save($left);
        }
    }

    /** Forgets an email given at checkout, as a privacy request asks. */
    public function forgetEmail(string $email): int
    {
        return $this->abandoned->forgetEmail(strtolower(trim($email)));
    }

    /** The carts a deleted customer left at checkout no longer name the account; they keep their email. */
    public function forgetCustomer(int $customerId): void
    {
        $this->abandoned->forgetCustomer($customerId);
    }

    /** Forgets the emails of carts left longer ago than carts are kept, daily. */
    public function forgetOld(): int
    {
        return $this->abandoned->forgetEmailsBefore($this->clock->now()->sub(new \DateInterval(CartService::KEEP_FOR)));
    }
}
