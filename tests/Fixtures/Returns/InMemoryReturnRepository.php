<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Returns;

use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnRepository;

final class InMemoryReturnRepository implements ReturnRepository
{
    /** @var array<int, ItemReturn> */
    private array $returns = [];
    private int $nextId = 1;

    public function find(int $id): ?ItemReturn
    {
        return $this->returns[$id] ?? null;
    }

    public function orderIds(): array
    {
        return array_values(array_unique(array_map(static fn(ItemReturn $return): int => $return->orderId(), $this->returns)));
    }

    public function forOrder(int $orderId): array
    {
        return array_values(array_filter($this->returns, static fn(ItemReturn $return): bool => $return->orderId() === $orderId));
    }

    public function save(ItemReturn $return): void
    {
        if (!\in_array($return, $this->returns, true)) {
            $id = $this->nextId++;
            new \ReflectionProperty(ItemReturn::class, 'id')->setValue($return, $id);
            $this->returns[$id] = $return;
        }
    }
}
