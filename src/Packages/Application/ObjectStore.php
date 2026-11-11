<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ObjectQuery;

/**
 * The records an extension keeps: its own objects, and its fields on the shop's products, orders and customers.
 * Every call is scoped to one extension; one extension never sees another's records.
 */
interface ObjectStore
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $indexed the fields to index, from the declaration
     *
     * @return array<string, mixed> the record as saved
     */
    public function create(string $extension, string $type, array $data, array $indexed): array;

    /** @return array<string, mixed>|null */
    public function find(string $extension, string $type, int $id): ?array;

    /**
     * @param array<string, mixed> $data    the fields to change; the rest stay
     * @param list<string>         $indexed
     *
     * @return array<string, mixed>|null the record as saved, null when there is none
     */
    public function update(string $extension, string $type, int $id, array $data, array $indexed): ?array;

    public function delete(string $extension, string $type, int $id): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function query(string $extension, string $type, ObjectQuery $query): array;

    public function count(string $extension, string $type, ObjectQuery $query): int;

    /** A field of the extension's on one of the shop's records. */
    public function fieldValue(string $extension, string $kind, int $ownerId, string $field): mixed;

    /** @return array<string, mixed> every field of the extension's on the record */
    public function fieldsOf(string $extension, string $kind, int $ownerId): array;

    /**
     * The extension's fields on many records at once, for a list or an export.
     *
     * @param list<int> $ownerIds
     *
     * @return array<int, array<string, mixed>> by record, the fields set on it
     */
    public function fieldsOfMany(string $extension, string $kind, array $ownerIds): array;

    public function setField(string $extension, string $kind, int $ownerId, string $field, mixed $value): void;

    /**
     * Runs the work inside a transaction that is rolled back afterwards, whatever it did: a test's records and
     * field values leave no trace.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function isolated(callable $work): mixed;

    /**
     * Whether the shop's database can still be written in this request: a write it refused (a broken constraint)
     * leaves nothing written after it, so the tests after one that did that cannot run.
     */
    public function intact(): bool;

    /** Everything of the extension: on uninstalling. */
    public function forgetExtension(string $extension): void;

    /** Everything about a customer, across extensions: on erasure. */
    public function forgetCustomer(int $customerId): void;

    /**
     * Everything about an order, across extensions: its fields and the records that refer to it, when its customer
     * is erased from it.
     */
    public function forgetOrder(int $orderId): void;

    /**
     * The extension's records that refer to one of the orders, for the personal data export.
     *
     * @param list<int> $orderIds
     *
     * @return list<array<string, mixed>>
     */
    public function ofOrders(string $extension, array $orderIds): array;

    /**
     * The extension's records that refer to a customer, for the personal data export.
     *
     * @return list<array<string, mixed>>
     */
    public function ofCustomer(string $extension, int $customerId): array;

    /**
     * Everything of the extension, for export.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $extension): array;
}
