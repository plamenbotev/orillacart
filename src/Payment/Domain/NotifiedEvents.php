<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

interface NotifiedEvents
{
    public function wasHandled(string $gateway, string $eventId): bool;

    public function remember(NotifiedEvent $event): void;

    /** Forgets the events received before then. */
    public function forgetBefore(\DateTimeImmutable $before): void;
}
