<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

interface TaxExemptionRepository
{
    public function find(int $id): ?TaxExemption;

    /** @return list<TaxExemption> newest first */
    public function ofCustomer(int $customerId): array;

    public function save(TaxExemption $exemption): void;

    public function remove(TaxExemption $exemption): void;

    public function removeOfCustomer(int $customerId): void;

    /**
     * Approved exemptions running out on these days whose customers were not reminded yet.
     *
     * @return list<TaxExemption>
     */
    public function expiringBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array;
}
