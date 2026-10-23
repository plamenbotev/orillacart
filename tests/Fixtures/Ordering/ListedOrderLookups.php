<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Application\OrderLookup;
use OrillaCart\Ordering\Application\OrderLookups;
use OrillaCart\Ordering\Application\OrderMark;
use OrillaCart\Ordering\Infrastructure\TaggedOrderLookups;

/**
 * The lookups and marks a test shop adds as it builds the areas that give them, used as the container's are.
 */
final class ListedOrderLookups implements OrderLookups
{
    /** @var list<OrderLookup> */
    private array $lookups = [];
    /** @var list<OrderMark> */
    private array $marks = [];

    public function add(OrderLookup|OrderMark ...$found): void
    {
        foreach ($found as $one) {
            if ($one instanceof OrderLookup) {
                $this->lookups[] = $one;
            }
            if ($one instanceof OrderMark) {
                $this->marks[] = $one;
            }
        }
    }

    public function ordersMatching(string $text): array
    {
        return $this->tagged()->ordersMatching($text);
    }

    public function marks(): array
    {
        return $this->tagged()->marks();
    }

    public function marked(string $key): ?array
    {
        return $this->tagged()->marked($key);
    }

    private function tagged(): TaggedOrderLookups
    {
        return new TaggedOrderLookups($this->lookups, $this->marks);
    }
}
