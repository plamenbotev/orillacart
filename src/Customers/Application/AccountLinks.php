<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

/**
 * Where signing up sends people: the shop's account page, to finish making the account and to come back to,
 * and WordPress's log-in and lost-password pages.
 */
interface AccountLinks
{
    public function accountUrl(): string;

    public function confirmUrl(string $token): string;

    public function loginUrl(): string;

    public function lostPasswordUrl(): string;
}
