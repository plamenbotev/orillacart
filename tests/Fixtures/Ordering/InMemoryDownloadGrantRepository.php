<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\DownloadGrantRepository;
use OrillaCart\Ordering\Domain\Order;

/**
 * Finds grants through the orders that hold them, numbering new ones as the database does.
 */
final class InMemoryDownloadGrantRepository implements DownloadGrantRepository
{
    private int $nextId = 1;

    public function __construct(private readonly InMemoryOrderRepository $orders) {}

    public function findByToken(string $token): ?DownloadGrant
    {
        foreach ($this->all() as $grant) {
            if ($grant->token() === $token) {
                return $grant;
            }
        }

        return null;
    }

    public function consume(DownloadGrant $grant, \DateTimeImmutable $at, string $by = '', int $until = 0): bool
    {
        $remaining = $grant->remaining();
        if ($remaining !== null && $remaining < 1) {
            return false;
        }
        new \ReflectionProperty(DownloadGrant::class, 'remaining')->setValue($grant, $remaining === null ? null : $remaining - 1);
        new \ReflectionProperty(DownloadGrant::class, 'downloads')->setValue($grant, $grant->downloads() + 1);
        new \ReflectionProperty(DownloadGrant::class, 'lastDownloadedAt')->setValue($grant, $at);
        new \ReflectionProperty(DownloadGrant::class, 'lastDownloadedBy')->setValue($grant, $by);
        new \ReflectionProperty(DownloadGrant::class, 'resumesLeft')->setValue($grant, DownloadGrant::RESUMES);
        new \ReflectionProperty(DownloadGrant::class, 'sentUntil')->setValue($grant, $until);

        return true;
    }

    public function resume(DownloadGrant $grant, string $by, int $least = \PHP_INT_MAX, int $until = 0): bool
    {
        if ($grant->resumesLeft() < 1 || $grant->lastDownloadedBy() !== $by || $grant->sentUntil() > $least) {
            return false;
        }
        new \ReflectionProperty(DownloadGrant::class, 'resumesLeft')->setValue($grant, $grant->resumesLeft() - 1);
        new \ReflectionProperty(DownloadGrant::class, 'sentUntil')->setValue($grant, $until);

        return true;
    }

    public function sentOnly(DownloadGrant $grant, int $until): void
    {
        if ($grant->sentUntil() > $until) {
            new \ReflectionProperty(DownloadGrant::class, 'sentUntil')->setValue($grant, $until);
        }
    }

    public function ofCustomer(int $customerId): array
    {
        return array_values(array_filter($this->all(), static fn(DownloadGrant $grant): bool => $grant->order()->customerId() === $customerId && $grant->order()->isPaid()));
    }

    public function isStorageKeyUsed(string $storageKey): bool
    {
        foreach ($this->orders->orders as $order) {
            foreach ($order->lines() as $line) {
                foreach ($line->downloads() as $file) {
                    if ($file->storageKey === $storageKey) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return list<DownloadGrant> */
    private function all(): array
    {
        $id = new \ReflectionProperty(DownloadGrant::class, 'id');
        $grants = array_merge(...array_map(static fn(Order $order): array => $order->grants(), array_values($this->orders->orders)));
        foreach ($grants as $grant) {
            if ($id->getValue($grant) === null) {
                $id->setValue($grant, $this->nextId++);
            }
        }

        return $grants;
    }
}
