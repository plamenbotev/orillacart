<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shipping;

use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ConsignmentRepository;

final class InMemoryConsignmentRepository implements ConsignmentRepository
{
    /** @var array<int, Consignment> */
    public array $consignments = [];
    private int $nextId = 1;

    public function find(int $id): ?Consignment
    {
        return $this->consignments[$id] ?? null;
    }

    public function forOrder(int $orderId): array
    {
        return array_values(array_filter($this->consignments, static fn(Consignment $consignment): bool => $consignment->orderId() === $orderId));
    }

    public function open(int $limit): array
    {
        return \array_slice(array_values(array_filter($this->consignments, static fn(Consignment $consignment): bool => $consignment->status()->isOpen())), 0, $limit);
    }

    public function save(Consignment $consignment): void
    {
        if (!\in_array($consignment, $this->consignments, true)) {
            $id = $this->nextId++;
            new \ReflectionProperty(Consignment::class, 'id')->setValue($consignment, $id);
            $this->consignments[$id] = $consignment;
        }
    }
}
