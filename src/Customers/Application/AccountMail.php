<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

/**
 * The emails signing up sends: the link to make the account, or, for an email that has one, word of it. Neither
 * says anything to whoever asked on the page. Once the account is made, a welcome.
 */
interface AccountMail
{
    /**
     * @param bool $guestOrders whether the account takes the orders placed with the email before, as the email says
     */
    public function confirm(string $email, string $firstName, string $link, \DateTimeImmutable $until, bool $guestOrders = true): void;

    public function exists(string $email, string $loginUrl, string $lostPasswordUrl): void;

    public function welcome(string $email, string $firstName, string $accountUrl): void;
}
