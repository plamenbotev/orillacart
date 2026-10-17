<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * The email settings, with what applies when a field is left empty.
 */
final readonly class EmailSettingsView
{
    /**
     * @param list<string> $disabled
     * @param list<string> $kinds    every email the shop can send
     */
    public function __construct(
        public ?string $fromName,
        public ?string $fromAddress,
        public ?string $shopAddress,
        public array $disabled,
        public string $defaultName,
        public string $defaultAddress,
        public array $kinds,
    ) {}
}
