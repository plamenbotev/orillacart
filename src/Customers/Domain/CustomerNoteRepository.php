<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

interface CustomerNoteRepository
{
    /** @return list<CustomerNote> newest first */
    public function ofCustomer(int $customerId): array;

    public function find(int $id): ?CustomerNote;

    public function save(CustomerNote $note): void;

    public function remove(CustomerNote $note): void;

    /** A customer's account went: so do the notes about them. */
    public function removeOfCustomer(int $customerId): void;
}
