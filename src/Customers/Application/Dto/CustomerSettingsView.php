<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

final readonly class CustomerSettingsView
{
    /**
     * @param list<string> $modes
     */
    public function __construct(
        public string $accounts,
        public array $modes,
    ) {}
}
