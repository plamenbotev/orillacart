<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

interface AbandonedCartRepository
{
    public function find(int $id): ?AbandonedCart;

    public function save(AbandonedCart $abandoned): void;

    /**
     * Carts at checkout, not changed and not at checkout since the moment, that hold something and are not
     * counted abandoned yet; the oldest first.
     *
     * @return list<Cart>
     */
    public function leftSince(\DateTimeImmutable $before, int $limit): array;

    /**
     * What was left between the moments, in the currency.
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
    public function forgetEmail(string $email): int;

    /** Forgets the emails of carts left before the moment: the carts are gone. */
    public function forgetEmailsBefore(\DateTimeImmutable $before): int;
}
