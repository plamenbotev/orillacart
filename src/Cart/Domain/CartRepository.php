<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

interface CartRepository
{
    /**
     * @param bool $lock whether to lock the cart's row until the transaction ends (SELECT … FOR UPDATE); only
     *                   inside a transaction
     */
    public function find(int $id, bool $lock = false): ?Cart;

    public function findByToken(string $token): ?Cart;

    /** The cart a reminder's link brings back. */
    public function findByRecoverToken(string $token): ?Cart;

    /** The customer's most recently changed cart. */
    public function findByCustomer(int $customerId): ?Cart;

    public function save(Cart $cart): void;

    public function remove(Cart $cart): void;

    /** Removes the carts of a customer whose account is gone. */
    public function removeOfCustomer(int $customerId): void;

    /** @return int how many carts not changed since the moment were removed */
    public function removeUnchangedSince(\DateTimeImmutable $before): int;
}
