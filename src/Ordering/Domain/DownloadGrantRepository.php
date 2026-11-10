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
    public function consume(DownloadGrant $grant, \DateTimeImmutable $at, string $by = ''): bool;

    /**
     * Takes one resume of the last counted download, when $by took it and any are left: safe when two start at once.
     *
     * @return bool false when none were left
     */
    public function resume(DownloadGrant $grant, string $by): bool;

    /** @return list<DownloadGrant> of the customer's paid orders, newest order first */
    public function ofCustomer(int $customerId): array;

    /** Whether an order still needs the stored file: a grant, or an order line that will grant it once paid. */
    public function isStorageKeyUsed(string $storageKey): bool;
}
