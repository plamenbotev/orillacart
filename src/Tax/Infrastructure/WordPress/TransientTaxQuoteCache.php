<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Tax\Application\TaxQuoteCache;

/**
 * Tax services' answers in WordPress transients: in the object cache where the site has one, in the options
 * table otherwise, gone after their time either way.
 */
final readonly class TransientTaxQuoteCache implements TaxQuoteCache
{
    private const string PREFIX = 'orillacart_tax_';

    public function get(string $key): ?array
    {
        $rows = get_transient(self::name($key));
        if (!\is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $kept = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                return null;
            }
            $values = [];
            foreach ($row as $name => $value) {
                if (!\is_string($name) || !(\is_string($value) || \is_bool($value) || $value === null)) {
                    return null;
                }
                $values[$name] = $value;
            }
            $kept[] = $values;
        }

        return $kept;
    }

    public function put(string $key, array $rows, int $seconds): void
    {
        set_transient(self::name($key), $rows, $seconds);
    }

    /** Transient names are at most 172 characters. */
    private static function name(string $key): string
    {
        return self::PREFIX . hash('sha256', $key);
    }
}
