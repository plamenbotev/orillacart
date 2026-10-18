<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\WordPress;

use OrillaCart\Shipping\Application\RateCache;

/**
 * Quoted rates in WordPress transients: in the object cache where the site has one, in the options table
 * otherwise, gone after a few minutes either way.
 */
final readonly class TransientRateCache implements RateCache
{
    private const string PREFIX = 'orillacart_rates_';

    public function get(string $key): ?array
    {
        $rows = get_transient(self::name($key));
        if (!\is_array($rows)) {
            return null;
        }
        $rates = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['code'] ?? null) || !\is_string($row['name'] ?? null) || !\is_string($row['price'] ?? null)) {
                return null;
            }
            $rates[] = ['code' => $row['code'], 'name' => $row['name'], 'price' => $row['price'], 'days' => \is_int($row['days'] ?? null) ? $row['days'] : null];
        }

        return $rates;
    }

    public function put(string $key, array $rates, int $seconds): void
    {
        set_transient(self::name($key), $rates, $seconds);
    }

    /** Transient names are at most 172 characters. */
    private static function name(string $key): string
    {
        return self::PREFIX . hash('sha256', $key);
    }
}
