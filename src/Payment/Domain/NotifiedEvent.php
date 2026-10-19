<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A provider's event the shop handled: the same one again is ignored. Kept a month, longer than providers retry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_events')]
#[ORM\Index(name: 'payment_event_received', columns: ['received_at'])]
class NotifiedEvent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        private string $gateway,
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $eventId,
        #[ORM\Column]
        private \DateTimeImmutable $receivedAt,
    ) {}

    public function eventId(): string
    {
        return $this->eventId;
    }
}
