<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\ClaimedJob;
use OrillaCart\Search\Application\Dto\QueueStatus;

/**
 * Products whose search documents are to be built again. One entry per product, however often it changes
 * before a worker gets to it; several workers can take from it at once without taking the same product.
 */
interface IndexQueue
{
    /**
     * Queues the products, due at the given moment or sooner if they already were.
     *
     * @param list<int> $productIds
     */
    public function push(array $productIds, \DateTimeImmutable $at): void;

    /**
     * Takes due products for a while, so other workers skip them. A worker that dies leaves them to be taken
     * again once the while is over.
     *
     * @return list<ClaimedJob>
     */
    public function claim(int $limit, \DateTimeImmutable $now, \DateTimeImmutable $lockedUntil): array;

    /**
     * The product's document is built. Unless it changed meanwhile, it leaves the queue, or waits until
     * $next (a sale starting or ending).
     */
    public function complete(ClaimedJob $job, ?\DateTimeImmutable $next): void;

    /**
     * Building failed; it is tried again at $retryAt, until it failed too often.
     */
    public function fail(ClaimedJob $job, string $error, \DateTimeImmutable $retryAt): void;

    public function status(\DateTimeImmutable $now): QueueStatus;
}
