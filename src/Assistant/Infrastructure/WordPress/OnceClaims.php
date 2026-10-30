<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

/**
 * What may happen once (a yes spent, a question's state redeemed), claimed in one statement the database decides:
 * a row of the site's options with a name of its own, inserted unless it is there. Of two retries sent at once,
 * only one claims it; reading then writing would let both through. Not the object cache: one that evicts a
 * claim, or is not shared by every server, would let it through again. A claim is kept until $until, then cleared.
 */
final readonly class OnceClaims
{
    private const string PREFIX = '_orillacart_once_';

    /** Whether this call is the one that claimed it; false when it was claimed before. */
    public function claim(string $what, int $until): bool
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $this->clearRunOut($wpdb);
        $inserted = $wpdb->query((string) $wpdb->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $wpdb->options,
            self::PREFIX . hash('sha256', $what),
            (string) $until,
        ));

        return $inserted === 1;
    }

    private function clearRunOut(\wpdb $wpdb): void
    {
        $wpdb->query((string) $wpdb->prepare(
            'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
            $wpdb->options,
            $wpdb->esc_like(self::PREFIX) . '%',
            time(),
        ));
    }
}
