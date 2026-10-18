<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Cart;

use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Cart\Domain\CartLine;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryCartRepository implements CartRepository
{
    /** @var array<int, Cart> */
    public array $carts = [];
    private int $nextCartId = 1;
    private int $nextLineId = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function find(int $id, bool $lock = false): ?Cart
    {
        if ($lock) {
            $this->transactions?->requireOpen();
        }

        return $this->carts[$id] ?? null;
    }

    public function findByToken(string $token): ?Cart
    {
        $hash = Cart::hash($token);
        foreach ($this->carts as $cart) {
            if (new \ReflectionProperty(Cart::class, 'tokenHash')->getValue($cart) === $hash) {
                return $cart;
            }
        }

        return null;
    }

    public function findByCustomer(int $customerId): ?Cart
    {
        $found = null;
        foreach ($this->carts as $cart) {
            if ($cart->customerId() === $customerId) {
                $found = $cart;
            }
        }

        return $found;
    }

    public function save(Cart $cart): void
    {
        $id = new \ReflectionProperty(Cart::class, 'id');
        if ($id->getValue($cart) === null) {
            $id->setValue($cart, $this->nextCartId++);
        }
        $lineId = new \ReflectionProperty(CartLine::class, 'id');
        foreach ($cart->lines() as $line) {
            if (!$line->isSaved()) {
                $lineId->setValue($line, $this->nextLineId++);
            }
        }
        $this->carts[$cart->id()] = $cart;
    }

    public function remove(Cart $cart): void
    {
        unset($this->carts[$cart->id()]);
    }

    public function removeOfCustomer(int $customerId): void
    {
        $this->carts = array_filter($this->carts, static fn(Cart $cart): bool => $cart->customerId() !== $customerId);
    }

    public function removeUnchangedSince(\DateTimeImmutable $before): int
    {
        $removed = 0;
        foreach ($this->carts as $id => $cart) {
            if (new \ReflectionProperty(Cart::class, 'updatedAt')->getValue($cart) < $before) {
                unset($this->carts[$id]);
                ++$removed;
            }
        }

        return $removed;
    }
}
