<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

final readonly class SavePackageSettings
{
    /**
     * @param array<string, string|bool> $settings by key; a secret sent empty keeps its value, false clears it
     */
    public function __construct(public array $settings = []) {}
}
