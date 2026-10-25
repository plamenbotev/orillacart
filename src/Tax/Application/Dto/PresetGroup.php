<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * A tax group the setup makes, with its rates.
 */
final readonly class PresetGroup
{
    public const string STANDARD = 'standard';
    public const string REDUCED = 'reduced';
    public const string SECOND_REDUCED = 'second_reduced';
    public const string SUPER_REDUCED = 'super_reduced';
    public const string ZERO = 'zero';

    /**
     * @param list<SaveTaxRate> $rates
     */
    public function __construct(
        public string $kind,
        public array $rates,
    ) {}
}
