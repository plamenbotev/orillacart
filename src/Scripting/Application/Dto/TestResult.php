<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/** One `test` declaration run: whether it passed, and if not what it said and where. */
final readonly class TestResult
{
    /**
     * @param list<array{method: string, url: string, status: int}>       $http the requests the test made, as the fakes answered them
     * @param list<array{name: string, payload: mixed, at: string|null}>  $jobs the jobs the test pushed, recorded instead of queued
     * @param array<string, int|float>                                     $used the budget the run used
     */
    public function __construct(
        public string $name,
        public bool $ok,
        public ?string $message,
        public ?int $line,
        public array $http,
        public array $jobs,
        public array $used,
    ) {}
}
