<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Catalog;

use OrillaCart\Catalog\Domain\PriceHistoryRepository;
use OrillaCart\Catalog\Domain\PricePoint;

final class InMemoryPriceHistory implements PriceHistoryRepository
{
    /** @var list<PricePoint> */
    public array $points = [];

    public function latest(array $items): array
    {
        $latest = [];
        foreach ($this->points as $point) {
            if (\in_array($point->item(), $items, true)) {
                $latest[$point->item()] = $point;
            }
        }

        return $latest;
    }

    public function since(array $items, \DateTimeImmutable $since): array
    {
        $found = [];
        $before = [];
        foreach ($this->points as $point) {
            if (!\in_array($point->item(), $items, true)) {
                continue;
            }
            if ($point->recordedAt() >= $since) {
                $found[] = $point;
            } else {
                $before[$point->item()] = $point;
            }
        }
        $all = [...array_values($before), ...$found];
        usort($all, static fn(PricePoint $a, PricePoint $b): int => [$a->item(), $a->recordedAt()] <=> [$b->item(), $b->recordedAt()]);

        return $all;
    }

    public function add(PricePoint $point): void
    {
        $this->points[] = $point;
    }
}
