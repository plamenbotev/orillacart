<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Cart;

use OrillaCart\Cart\Domain\AbandonedCart;
use OrillaCart\Cart\Domain\AbandonedCartRepository;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Shared\Domain\Money;

final class InMemoryAbandonedCartRepository implements AbandonedCartRepository
{
    /** @var array<int, AbandonedCart> */
    public array $abandoned = [];
    private int $nextId = 1;

    public function __construct(private readonly InMemoryCartRepository $carts) {}

    public function find(int $id): ?AbandonedCart
    {
        return $this->abandoned[$id] ?? null;
    }

    public function save(AbandonedCart $abandoned): void
    {
        $id = new \ReflectionProperty(AbandonedCart::class, 'id');
        if ($id->getValue($abandoned) === null) {
            $id->setValue($abandoned, $this->nextId++);
        }
        $this->abandoned[$abandoned->id()] = $abandoned;
    }

    public function claimReminder(int $id, \DateTimeImmutable $at): bool
    {
        $left = $this->abandoned[$id] ?? null;
        if ($left === null || $left->remindedAt() !== null) {
            return false;
        }
        $left->reminded($at);

        return true;
    }

    public function releaseReminder(int $id): void
    {
        $left = $this->abandoned[$id] ?? null;
        if ($left !== null) {
            new \ReflectionProperty(AbandonedCart::class, 'remindedAt')->setValue($left, null);
        }
    }

    public function leftSince(\DateTimeImmutable $before, int $limit): array
    {
        $placing = new \ReflectionProperty(Cart::class, 'placingSince');
        $left = array_values(array_filter($this->carts->carts, static fn(Cart $cart): bool => $cart->checkoutAt() !== null
            && $cart->abandonedId() === null
            && $cart->checkoutAt() < $before
            && $cart->updatedAt() < $before
            && !$cart->isEmpty()
            && ($placing->getValue($cart) === null || $placing->getValue($cart) < $before)));
        usort($left, static fn(Cart $a, Cart $b): int => $a->checkoutAt() <=> $b->checkoutAt());

        return \array_slice($left, 0, $limit);
    }

    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency): array
    {
        $totals = ['abandoned' => 0, 'value' => Money::zero(), 'reminded' => 0, 'recovered' => 0, 'recovered_value' => Money::zero(), 'recovered_after_reminder' => 0];
        foreach ($this->between($from, $until, $currency) as $left) {
            ++$totals['abandoned'];
            $totals['value'] = $totals['value']->add(Money::of($left->subtotal()));
            $totals['reminded'] += $left->remindedAt() === null ? 0 : 1;
            if ($left->isRecovered()) {
                ++$totals['recovered'];
                $totals['recovered_value'] = $totals['recovered_value']->add(Money::of($left->orderTotal() ?? '0'));
                $totals['recovered_after_reminder'] += $left->remindedAt() === null ? 0 : 1;
            }
        }

        return [
            'abandoned' => $totals['abandoned'],
            'value' => (string) $totals['value'],
            'reminded' => $totals['reminded'],
            'recovered' => $totals['recovered'],
            'recovered_value' => (string) $totals['recovered_value'],
            'recovered_after_reminder' => $totals['recovered_after_reminder'],
        ];
    }

    public function latest(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency, int $limit): array
    {
        $left = $this->between($from, $until, $currency);
        usort($left, static fn(AbandonedCart $a, AbandonedCart $b): int => [$b->abandonedAt(), $b->id()] <=> [$a->abandonedAt(), $a->id()]);

        return \array_slice($left, 0, $limit);
    }

    public function ofEmail(string $email): array
    {
        return array_values(array_filter($this->abandoned, static fn(AbandonedCart $cart): bool => $cart->email() === $email));
    }

    public function forgetEmail(string $email): int
    {
        $forgotten = 0;
        foreach ($this->carts->carts as $cart) {
            if ($cart->contactEmail() === $email) {
                $cart->forgetContact();
                ++$forgotten;
            }
        }
        foreach ($this->abandoned as $left) {
            if ($left->email() === $email) {
                $left->forgetEmail();
                ++$forgotten;
            }
        }

        return $forgotten;
    }

    public function forgetEmailsBefore(\DateTimeImmutable $before): int
    {
        $forgotten = 0;
        foreach ($this->abandoned as $left) {
            if ($left->email() !== null && $left->abandonedAt() < $before) {
                $left->forgetEmail();
                ++$forgotten;
            }
        }

        return $forgotten;
    }

    /** @return list<AbandonedCart> */
    private function between(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency): array
    {
        return array_values(array_filter($this->abandoned, static fn(AbandonedCart $left): bool => $left->abandonedAt() >= $from && $left->abandonedAt() < $until && $left->currency() === $currency));
    }
}
