<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

interface BackInStockRequests
{
    public function has(string $item, string $email): bool;

    /** Whether anyone waits for the item. */
    public function wanted(string $item): bool;

    /** @return list<BackInStockRequest> */
    public function forItem(string $item, int $limit): array;

    public function save(BackInStockRequest $request): void;

    public function remove(BackInStockRequest $request): void;

    /** @return int how many were forgotten */
    public function forgetEmail(string $email): int;

    /** @return int how many were forgotten */
    public function forgetBefore(\DateTimeImmutable $before): int;
}
