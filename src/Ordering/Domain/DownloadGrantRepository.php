<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

interface DownloadGrantRepository
{
    public function findByToken(string $token): ?DownloadGrant;

    /**
     * Counts one download, unless none are left: safe when two downloads start at the same moment.
     *
     * @return bool false when no downloads were left
     */
    public function consume(DownloadGrant $grant): bool;

    /** @return list<DownloadGrant> of the customer's paid orders, newest order first */
    public function ofCustomer(int $customerId): array;

    /** Whether an order still needs the stored file: a grant, or an order line that will grant it once paid. */
    public function isStorageKeyUsed(string $storageKey): bool;
}
