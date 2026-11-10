<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

interface DownloadGrantRepository
{
    public function findByToken(string $token): ?DownloadGrant;

    /**
     * Counts one download, unless none are left: safe when two downloads start at the same moment.
     *
     * @param int $until how far into the file it is sent (DownloadGrant::sentUntil())
     *
     * @return bool false when no downloads were left
     */
    public function consume(DownloadGrant $grant, \DateTimeImmutable $at, string $by = '', int $until = 0): bool;

    /**
     * Takes one resume of the last counted download, when $by took it, any are left, and it starts from about
     * where the download was sent to (no further back than $least): safe when two start at once.
     *
     * @param int $least the byte the resume starts at, plus what it may go back over (DownloadGrant::overlap())
     * @param int $until how far into the file it is sent
     *
     * @return bool false when none were left
     */
    public function resume(DownloadGrant $grant, string $by, int $least = \PHP_INT_MAX, int $until = 0): bool;

    /** The download broke off: only this far into the file went out. */
    public function sentOnly(DownloadGrant $grant, int $until): void;

    /** @return list<DownloadGrant> of the customer's paid orders, newest order first */
    public function ofCustomer(int $customerId): array;

    /** Whether an order still needs the stored file: a grant, or an order line that will grant it once paid. */
    public function isStorageKeyUsed(string $storageKey): bool;
}
