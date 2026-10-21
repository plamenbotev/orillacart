<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

use OrillaCart\Reviews\Domain\Rating;

/** A product's stars: the average, how many reviews, and how many gave each number of stars. */
final readonly class RatingView
{
    /**
     * @param array<int, int> $stars from 5 stars down to 1
     */
    public function __construct(
        public string $average,
        public int $count,
        public array $stars,
    ) {}

    public static function of(Rating $rating): self
    {
        $stars = [];
        foreach ([5, 4, 3, 2, 1] as $number) {
            $stars[$number] = $rating->stars[$number] ?? 0;
        }

        return new self($rating->average(), $rating->count(), $stars);
    }
}
