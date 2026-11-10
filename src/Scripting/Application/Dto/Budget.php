<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/** What one run may use. The shop's ceiling; an extension's manager may tighten it, never loosen it beyond. */
final readonly class Budget
{
    public function __construct(
        public int $steps = 100000,
        public float $seconds = 2.0,
        public int $calls = 5000,
        public int $httpCalls = 5,
        public int $queries = 50,
        public int $emails = 5,
        public int $bytes = 16 * 1024 * 1024,
    ) {}
}
