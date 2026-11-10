<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * An order a consumer may withdraw from, and what of it: its lines, with how many of each. Whoever knows the
 * number and the email may ask, so it says nothing of the buyer: not their name, address or what they paid. What
 * they declare is copied to the order's own email, so a withdrawal the buyer did not make is seen by them.
 */
final readonly class WithdrawableOrderView
{
    /**
     * @param list<array{line: int, name: string, quantity: int}> $lines
     */
    public function __construct(
        public int $number,
        public string $placedAt,
        public array $lines,
    ) {}
}
