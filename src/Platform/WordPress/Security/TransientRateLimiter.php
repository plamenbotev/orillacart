<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Http\Security\RateLimiter;

/**
 * Counts in transients (the object cache where the site has one), in fixed windows. The limits are
 * {guard: [how many, in seconds]}, changed with the filter orillacart_rate_limits; a guard without one is not
 * counted. The address is REMOTE_ADDR: behind a proxy, a site sets it from the forwarded header, as WordPress
 * expects.
 */
final readonly class TransientRateLimiter implements RateLimiter
{
    /** @var array<string, array{int, int}> */
    private const array LIMITS = [
        'accounts' => [5, 3600],
        'account_links' => [10, 3600],
        'coupons' => [20, 600],
        'checkout' => [30, 600],
        'session' => [120, 600],
    ];

    public function hit(string $guard, string $caller): ?int
    {
        $limits = apply_filters('orillacart_rate_limits', self::LIMITS);
        $limit = \is_array($limits) ? ($limits[$guard] ?? null) : null;
        if (!\is_array($limit) || !\is_int($limit[0] ?? null) || !\is_int($limit[1] ?? null) || $limit[1] < 1) {
            return null;
        }
        [$max, $seconds] = [$limit[0], $limit[1]];
        $window = intdiv(time(), $seconds);
        $key = 'orillacart_rl_' . md5("{$guard}|{$caller}|{$window}");
        $count = get_transient($key);
        $count = is_numeric($count) ? (int) $count + 1 : 1;
        set_transient($key, $count, $seconds);

        return $count > $max ? ($window + 1) * $seconds - time() : null;
    }
}
