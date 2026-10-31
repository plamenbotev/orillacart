<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Integrations\Application\ApiKeys;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The API keys a deleted WordPress user made go with them, and the OAuth tokens of the apps they connected: no
 * key stays listed for an account that is gone.
 */
#[AsEventListener]
final readonly class RevokeKeysOfDeletedUsers
{
    public function __construct(private ApiKeys $keys) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $this->keys->revokeOfUser($event->customerId);
    }
}
