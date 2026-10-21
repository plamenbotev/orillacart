<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

final readonly class ReviewSettings
{
    public function __construct(
        public ReviewPolicy $policy = ReviewPolicy::Anyone,
    ) {}
}
