<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Domain;

interface LoggedProblemRepository
{
    /** @return list<LoggedProblem> the latest seen first */
    public function page(int $offset, int $limit): array;

    public function total(): int;

    /** How many problems were seen since then. */
    public function seenSince(\DateTimeImmutable $at): int;

    public function clear(): void;

    public function forgetBefore(\DateTimeImmutable $at): void;
}
