<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

/**
 * The emails guests confirmed for ordering (EmailConfirmations), each under a key of the mailbox and the cart that
 * asked, which names neither; kept until the confirmation ends.
 */
interface ConfirmedEmails
{
    /** @param int $until a Unix time */
    public function keep(string $key, int $until): void;

    /** Until when the key is confirmed, as a Unix time; null for one that is not, or no longer kept. */
    public function until(string $key): ?int;
}
