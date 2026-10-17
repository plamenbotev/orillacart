<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

interface CartRepository
{
    public function find(int $id): ?Cart;

    public function findByToken(string $token): ?Cart;

    /** The customer's most recently changed cart. */
    public function findByCustomer(int $customerId): ?Cart;

    public function save(Cart $cart): void;

    /** @return int how many carts not changed since the moment were removed */
    public function removeUnchangedSince(\DateTimeImmutable $before): int;
}
