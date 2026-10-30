<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Returns;

use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnRepository;

/**
 * Another request's return repository: the first time a return is read under the lock, other work (the other
 * request's, which held the lock first) runs before the read, as it would have in the database.
 */
final class RacingReturnRepository implements ReturnRepository
{
    private ?\Closure $beforeLockedRead;

    public function __construct(private readonly ReturnRepository $inner, \Closure $beforeLockedRead)
    {
        $this->beforeLockedRead = $beforeLockedRead;
    }

    public function find(int $id, bool $lock = false): ?ItemReturn
    {
        if ($lock && $this->beforeLockedRead !== null) {
            $first = $this->beforeLockedRead;
            $this->beforeLockedRead = null;
            $first();
        }

        return $this->inner->find($id, $lock);
    }

    public function forOrder(int $orderId): array
    {
        return $this->inner->forOrder($orderId);
    }

    public function orderIds(): array
    {
        return $this->inner->orderIds();
    }

    public function save(ItemReturn $return): void
    {
        $this->inner->save($return);
    }
}
