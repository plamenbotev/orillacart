<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Domain\NotifiedEvent;
use OrillaCart\Payment\Domain\NotifiedEvents;

final class InMemoryNotifiedEvents implements NotifiedEvents
{
    /** @var array<string, \DateTimeImmutable> received, by gateway and event id */
    public array $events = [];

    public function wasHandled(string $gateway, string $eventId): bool
    {
        return isset($this->events["{$gateway}:{$eventId}"]);
    }

    public function remember(NotifiedEvent $event): void
    {
        $read = (fn(): array => [$this->gateway, $this->eventId, $this->receivedAt])->call($event);
        $this->events["{$read[0]}:{$read[1]}"] = $read[2];
    }

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->events = array_filter($this->events, static fn(\DateTimeImmutable $at): bool => $at >= $before);
    }

    public function lastReceivedAt(string $gateway): ?\DateTimeImmutable
    {
        $kept = array_filter($this->events, static fn(string $key): bool => str_starts_with($key, "{$gateway}:"), ARRAY_FILTER_USE_KEY);

        return $kept === [] ? null : max($kept);
    }
}
