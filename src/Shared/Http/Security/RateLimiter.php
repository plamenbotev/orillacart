<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

/**
 * Counts what visitors send, so one of them cannot try codes, emails or orders without end.
 */
interface RateLimiter
{
    /**
     * Counts one more and says whether it is within the limit.
     *
     * @param string $guard  the form: accounts, coupons, checkout…
     * @param string $caller who sends it: "ip:203.0.113.7", "cart:…"
     *
     * @return int|null null when allowed; otherwise the seconds until it is again
     */
    public function hit(string $guard, string $caller): ?int;
}
