<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

/** A page of a product's published reviews, newest first, under its stars. */
final readonly class ReviewPageView
{
    /**
     * @param list<ReviewView> $reviews
     */
    public function __construct(
        public ?RatingView $rating,
        public array $reviews,
        public int $page,
        public int $pages,
    ) {}
}
