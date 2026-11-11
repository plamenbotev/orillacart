<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** What the console got back: the value, or the failure, and what the run used. */
final readonly class RunResult
{
    /**
     * @param array{kind: string, message: string, data: mixed}|null $failure
     * @param array<string, int|float>                               $used
     * @param list<array{fn: string, file: string, line: int}>       $stack     where it failed, innermost first (design 71)
     * @param array<string, mixed>|null                              $recording the run's recording, when it was debugged
     */
    public function __construct(
        public bool $ok,
        public mixed $result,
        public ?array $failure,
        public ?string $stopped,
        public array $used,
        public array $stack = [],
        public ?array $recording = null,
    ) {}
}
