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

    public function consume(DownloadGrant $grant): bool
    {
        $remaining = $grant->remaining();
        if ($remaining !== null && $remaining < 1) {
            return false;
        }
        new \ReflectionProperty(DownloadGrant::class, 'remaining')->setValue($grant, $remaining === null ? null : $remaining - 1);
        new \ReflectionProperty(DownloadGrant::class, 'downloads')->setValue($grant, $grant->downloads() + 1);

        return true;
    }

    public function ofCustomer(int $customerId): array
    {
        return array_values(array_filter($this->all(), static fn(DownloadGrant $grant): bool => $grant->order()->customerId() === $customerId && $grant->order()->status()->isPaid()));
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
