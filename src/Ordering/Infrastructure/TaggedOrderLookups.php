<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure;

use OrillaCart\Ordering\Application\OrderLookup;
use OrillaCart\Ordering\Application\OrderLookups;
use OrillaCart\Ordering\Application\OrderMark;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The lookups and marks the container found by their tags.
 */
final readonly class TaggedOrderLookups implements OrderLookups
{
    /**
     * @param iterable<OrderLookup> $lookups
     * @param iterable<OrderMark>   $marks
     */
    public function __construct(
        #[AutowireIterator(OrderLookup::LOOKUP_TAG)]
        private iterable $lookups,
        #[AutowireIterator(OrderMark::MARK_TAG)]
        private iterable $marks,
    ) {}

    public function ordersMatching(string $text): array
    {
        if (mb_strlen($text) < OrderLookup::MIN_LENGTH) {
            return [];
        }
        $ids = [];
        foreach ($this->lookups as $lookup) {
            foreach (\array_slice($lookup->ordersMatching($text), 0, OrderLookup::MAX_FOUND) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    public function marks(): array
    {
        $marks = [];
        foreach ($this->marks as $mark) {
            $marks[$mark->key()] = $mark->label();
        }

        return $marks;
    }

    public function marked(string $key): ?array
    {
        foreach ($this->marks as $mark) {
            if ($mark->key() === $key) {
                return $mark->orderIds();
            }
        }

        return null;
    }
}
