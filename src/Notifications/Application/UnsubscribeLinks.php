<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/** The address an email's reader unsubscribes from the shop's newsletter at, when they are subscribed. */
interface UnsubscribeLinks
{
    public function for(string $email): ?string;
}
