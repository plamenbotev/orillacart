<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

/**
 * The emails signing up sends: the link to make the account, or, for an email that has one, word of it. Neither
 * says anything to whoever asked on the page.
 */
interface AccountMail
{
    public function confirm(string $email, string $firstName, string $link, \DateTimeImmutable $until): void;

    public function exists(string $email, string $loginUrl, string $lostPasswordUrl): void;
}
