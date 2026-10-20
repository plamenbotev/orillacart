<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Newsletter;

use OrillaCart\Newsletter\Domain\Subscriber;
use OrillaCart\Newsletter\Domain\SubscriberRepository;

final class InMemorySubscribers implements SubscriberRepository
{
    /** @var array<int, Subscriber> */
    public array $subscribers = [];
    private int $nextId = 1;

    public function findByEmail(string $email): ?Subscriber
    {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber->email() === Subscriber::normalize($email)) {
                return $subscriber;
            }
        }

        return null;
    }

    public function countSubscribed(): int
    {
        return \count(array_filter($this->subscribers, static fn(Subscriber $subscriber): bool => $subscriber->isSubscribed()));
    }

    public function subscribed(int $afterId, int $limit): array
    {
        $found = array_filter($this->subscribers, static fn(Subscriber $subscriber, int $id): bool => $subscriber->isSubscribed() && $id > $afterId, ARRAY_FILTER_USE_BOTH);
        ksort($found);

        return \array_slice(array_values($found), 0, $limit);
    }

    public function save(Subscriber $subscriber): void
    {
        $id = new \ReflectionProperty(Subscriber::class, 'id');
        if ($id->getValue($subscriber) === null) {
            $id->setValue($subscriber, $this->nextId++);
        }
        $this->subscribers[$subscriber->id()] = $subscriber;
    }

    public function remove(Subscriber $subscriber): void
    {
        unset($this->subscribers[$subscriber->id()]);
    }
}
