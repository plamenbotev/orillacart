<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Infrastructure\WordPress;

use OrillaCart\Checkout\Application\ConfirmedEmails;

/** Confirmed emails kept as transients, which WordPress forgets by itself when they end. */
final readonly class TransientConfirmedEmails implements ConfirmedEmails
{
    private const string KEY = 'orillacart_ce_';

    public function keep(string $key, int $until): void
    {
        $seconds = $until - time();
        if ($seconds > 0) {
            set_transient(self::KEY . $key, $until, $seconds);
        }
    }

    public function until(string $key): ?int
    {
        $until = get_transient(self::KEY . $key);

        return is_numeric($until) ? (int) $until : null;
    }
}
