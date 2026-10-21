<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

final readonly class ReviewSettingsView
{
    public function __construct(
        /** Who may review: off, anyone or buyers. */
        public string $policy,
    ) {}
}
