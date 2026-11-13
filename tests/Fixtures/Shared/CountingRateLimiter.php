<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shared;

use OrillaCart\Shared\Http\Security\RateLimiter;

/**
 * Counts hits by guard and caller, and refuses past the limits a test sets; guards without one are not limited.
 */
final class CountingRateLimiter implements RateLimiter
{
    /** @var array<string, int> hits by "guard|caller" */
    public array $hits = [];
    /** @var array<string, int> how many each guard allows */
    public array $limits = [];

    public function hit(string $guard, string $caller, ?array $limit = null): ?int
    {
        $key = "{$guard}|{$caller}";
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

        $most = $this->limits[$guard] ?? $limit[0] ?? null;

        return $most !== null && $this->hits[$key] > $most ? 60 : null;
    }
}
