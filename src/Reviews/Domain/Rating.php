<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

/**
 * A product's published reviews in numbers: how many gave each number of stars.
 */
final readonly class Rating
{
    /**
     * @param array<int, int> $stars how many reviews gave 1 to 5 stars, by stars
     */
    public function __construct(
        public array $stars = [],
    ) {}

    public function count(): int
    {
        return array_sum($this->stars);
    }

    /** The average stars, to one decimal; "0" without reviews. */
    public function average(): string
    {
        $count = $this->count();
        if ($count === 0) {
            return '0';
        }
        $total = 0;
        foreach ($this->stars as $stars => $reviews) {
            $total += $stars * $reviews;
        }

        return number_format($total / $count, 1, '.', '');
    }
}
