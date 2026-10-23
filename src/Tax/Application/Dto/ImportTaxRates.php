<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: rates from a CSV file, as the browser read it, added to a tax group or put in place of its rates.
 */
final readonly class ImportTaxRates
{
    public const int MAX_BYTES = 2_000_000;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_BYTES, maxMessage: 'The file is too large: up to 2 MB.', countUnit: Assert\Length::COUNT_BYTES)]
        public string $csv,
        /** The group's rates are removed first. */
        public bool $replace = false,
    ) {}
}
