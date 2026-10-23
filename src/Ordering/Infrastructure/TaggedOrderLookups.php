<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure;

use OrillaCart\Ordering\Application\OrderLookup;
use OrillaCart\Ordering\Application\OrderLookups;
use OrillaCart\Ordering\Application\OrderMark;
use OrillaCart\Ordering\Domain\OrderSearch;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The lookups and marks the container found by their tags, and the add-ons' order searches and filters. A search
 * that fails, or answers with something other than order numbers, is logged and finds nothing: the list still
 * shows. An add-on's filter whose id the shop uses already is left out.
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
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
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
        foreach ($this->extensions->orderSearches() as $search) {
            $found = $this->asked(\sprintf('The order search %s', $search->id()), static fn(): mixed => $search->findOrders($text));
            foreach (\array_slice($found, 0, OrderLookup::MAX_FOUND) as $id) {
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

    public function addOnFilters(): array
    {
        $own = $this->marks();
        $filters = [];
        foreach ($this->extensions->orderFilters() as $filter) {
            if (isset($own[$filter->id()]) || \in_array($filter->id(), OrderSearch::FACTS, true)) {
                $this->logger->error('An add-on\'s order filter uses the id "{id}", which is the shop\'s own. It is left out.', ['id' => $filter->id()]);
                continue;
            }
            $filters[$filter->id()] = $filter->label();
        }

        return $filters;
    }

    /**
     * What an add-on answered, when it is order numbers.
     *
     * @param \Closure(): mixed $ask
     *
     * @return list<int>
     */
    private function asked(string $what, \Closure $ask): array
    {
        try {
            $answer = $ask();
        } catch (\Throwable $e) {
            $this->logger->error('{what} failed: {message}', ['what' => $what, 'message' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
        $ids = [];
        foreach (\is_array($answer) ? $answer : [null] as $id) {
            if (!\is_int($id) || $id < 1) {
                $this->logger->error('{what} answered something other than a list of order numbers; it finds nothing.', ['what' => $what]);

                return [];
            }
            $ids[$id] = $id;
        }
        ksort($ids);

        return array_values($ids);
    }
}
