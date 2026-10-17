<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain\Event;

use OrillaCart\Shared\Domain\Event\DomainEvent;

/**
 * A product no longer offers a file. Orders that bought it may still need the stored copy.
 */
final readonly class DownloadRemoved implements DomainEvent
{
    public function __construct(public string $storageKey) {}
}
