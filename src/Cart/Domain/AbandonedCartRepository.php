<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

interface AbandonedCartRepository
{
    public function find(int $id): ?AbandonedCart;

    public function save(AbandonedCart $abandoned): void;

    /** The cart left at checkout the order was placed from, if it was. */
    public function ofOrder(int $orderId): ?AbandonedCart;

    /**
     * Marks the cart reminded, unless it was already: in one statement, so of two workers sending the same
     * reminder only one goes on.
     *
     * @return bool whether this call marked it
     */
    public function claimReminder(int $id, \DateTimeImmutable $at): bool;

    /** Unmarks a reminder that did not go out, so it is tried again. */
    public function releaseReminder(int $id): void;

    /**
     * Carts at checkout, not changed and not at checkout since the moment, that hold something and are not
     * counted abandoned yet; the oldest first.
     *
     * @return list<Cart>
     */
    public function leftSince(\DateTimeImmutable $before, int $limit): array;

    /**
     * What was left between the moments, in the currency; recovered: those whose order was paid.
     *
     * @return array{abandoned: int, value: string, reminded: int, recovered: int, recovered_value: string, recovered_after_reminder: int}
     */
    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency): array;

    /**
     * The latest left between the moments, in the currency.
     *
     * @return list<AbandonedCart>
     */
    public function latest(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency, int $limit): array;

    /** Forgets an email: in what was left, and in the carts that keep it. */
    /** @return list<AbandonedCart> the carts left with this email, for its owner's export */
    public function ofEmail(string $email): array;

    public function forgetEmail(string $email): int;

    /** Forgets the emails of carts left before the moment: the carts are gone. */
    public function forgetEmailsBefore(\DateTimeImmutable $before): int;
}
