<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Integrations\Application\Webhooks;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The webhooks a deleted WordPress user saved are switched off with them: nothing goes on in the name of an
 * account that is gone.
 */
#[AsEventListener]
final readonly class SwitchOffWebhooksOfDeletedUsers
{
    public function __construct(private Webhooks $webhooks) {}

    public function __invoke(CustomerDeleted $event): void
    {
        $this->webhooks->ownerChanged($event->customerId);
    }
}
