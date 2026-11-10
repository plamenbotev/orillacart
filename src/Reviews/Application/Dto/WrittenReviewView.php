<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

final readonly class WrittenReviewView
{
    public function __construct(
        /** published, held (for the shop to approve) or spam, which the visitor is told is held */
        public string $status,
        /** The review as kept; null for one a guest is answered about but that was not kept (see Reviews::write()). */
        public ?ReviewView $review,
    ) {}
}
