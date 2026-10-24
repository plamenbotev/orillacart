<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application\Dto;

use OrillaCart\Audit\Domain\LoggedProblem;

final readonly class ProblemView
{
    public function __construct(
        public int $id,
        /** warning, error, critical, alert or emergency */
        public string $level,
        public string $message,
        /** How many times it happened. */
        public int $count,
        public string $firstAt,
        public string $lastAt,
    ) {}

    public static function of(LoggedProblem $problem): self
    {
        return new self($problem->id(), $problem->level(), $problem->message(), $problem->count(), $problem->firstAt()->format(\DATE_ATOM), $problem->lastAt()->format(\DATE_ATOM));
    }
}
