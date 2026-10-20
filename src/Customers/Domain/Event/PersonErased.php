<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * A privacy request erased what the shop holds of an email, account or not: what keeps an email apart from
 * orders and accounts, such as an "email me when it is back" request, forgets it.
 */
final readonly class PersonErased implements DomainEvent
{
    public function __construct(public string $email) {}
}
