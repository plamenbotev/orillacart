<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Domain;

interface SubscriberRepository
{
    public function findByEmail(string $email): ?Subscriber;

    /** How many are subscribed now. */
    public function countSubscribed(): int;

    /**
     * Those subscribed now, oldest first, a page at a time.
     *
     * @return list<Subscriber>
     */
    public function subscribed(int $afterId, int $limit): array;

    public function save(Subscriber $subscriber): void;

    public function remove(Subscriber $subscriber): void;
}
