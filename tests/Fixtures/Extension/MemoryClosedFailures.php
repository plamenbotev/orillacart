<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Shared\Extension\ClosedFailures;

/** The failures of add-ons the shop cannot do without, in memory, all of them. */
final class MemoryClosedFailures implements ClosedFailures
{
    /** @var list<array{id: string, what: string, at: \DateTimeImmutable}> */
    public array $failed = [];

    public function failed(string $id, string $what): void
    {
        $this->failed[] = ['id' => $id, 'what' => $what, 'at' => new \DateTimeImmutable()];
    }

    public function since(\DateTimeImmutable $since): array
    {
        return array_values(array_filter(array_reverse($this->failed), static fn(array $failure): bool => $failure['at'] >= $since));
    }
}
