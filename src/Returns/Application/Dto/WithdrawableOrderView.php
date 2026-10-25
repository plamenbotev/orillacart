<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * An order a consumer may withdraw from, and what of it: its lines, with how many of each.
 */
final readonly class WithdrawableOrderView
{
    /**
     * @param list<array{line: int, name: string, quantity: int}> $lines
     */
    public function __construct(
        public int $number,
        public string $placedAt,
        /** The billing name, to start the declaration with. */
        public string $name,
        public array $lines,
    ) {}
}
