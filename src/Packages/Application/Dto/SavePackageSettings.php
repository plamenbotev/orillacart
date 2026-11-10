<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

final readonly class SavePackageSettings
{
    /**
     * @param array<string, string|bool> $settings by key; one left out keeps its value, so one can be changed alone; a secret sent empty keeps its value, false clears it
     */
    public function __construct(public array $settings = []) {}
}
