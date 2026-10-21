<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

final readonly class WrittenReviewView
{
    public function __construct(
        /** published, held (for the shop to approve) or spam, which the visitor is told is held */
        public string $status,
        public ReviewView $review,
    ) {}
}
