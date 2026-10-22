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
        'gift_cards' => [10, 600],
        'checkout' => [30, 600],
        'session' => [120, 600],
        'receipts' => [60, 600],
        'receipt_links' => [5, 3600],
        'order_lookup' => [5, 3600],
        'back_in_stock' => [10, 3600],
        // Each may cost the shop a lookup at an address register.
        'address_check' => [30, 3600],
        'returns' => [10, 3600],
        'reviews' => [10, 3600],
        'form_uploads' => [20, 3600],
    ];

    /**
     * The limits before sites change them: how many tries, in how many seconds, by guard.
     *
     * @return array<string, array{int, int}>
     */
    public static function limits(): array
    {
        return self::LIMITS;
    }

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
