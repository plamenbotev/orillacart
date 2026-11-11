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
     * @param list<array{function: string, arguments: list<mixed>}>       $effects the calls that would reach outside the shop, as the test's stand-ins took them
     */
    public function __construct(
        public string $name,
        public bool $ok,
        public ?string $message,
        public ?int $line,
        public array $http,
        public array $jobs,
        public array $used,
        public array $effects = [],
        /** @var list<array{fn: string, line: int}> where a failed test was, innermost first (design 71) */
        public array $stack = [],
        /** The test's recording, when it was the one debugged */
        public ?\OrillaCart\Scripting\Application\Recorder $recorder = null,
    ) {}
}
