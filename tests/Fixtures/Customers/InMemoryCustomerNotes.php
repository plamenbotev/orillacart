<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Customers;

use OrillaCart\Customers\Domain\CustomerNote;
use OrillaCart\Customers\Domain\CustomerNoteRepository;

final class InMemoryCustomerNotes implements CustomerNoteRepository
{
    /** @var array<int, CustomerNote> */
    public array $notes = [];
    private int $nextId = 1;

    public function ofCustomer(int $customerId): array
    {
        return array_values(array_reverse(array_filter($this->notes, static fn(CustomerNote $note): bool => $note->customerId() === $customerId)));
    }

    public function find(int $id): ?CustomerNote
    {
        return $this->notes[$id] ?? null;
    }

    public function save(CustomerNote $note): void
    {
        $id = new \ReflectionProperty(CustomerNote::class, 'id');
        if ($id->getValue($note) === null) {
            $id->setValue($note, $this->nextId++);
        }
        $this->notes[$note->id()] = $note;
    }

    public function remove(CustomerNote $note): void
    {
        unset($this->notes[$note->id()]);
    }

    public function removeOfCustomer(int $customerId): void
    {
        $this->notes = array_filter($this->notes, static fn(CustomerNote $note): bool => $note->customerId() !== $customerId);
    }
}
