<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A range of a rate: from min to max, both included; no max for no end.
 */
final readonly class SaveRange
{
    public const string MEASURE = '/^\d{1,14}(\.\d{1,4})?$/';

    public function __construct(
        #[Assert\Regex(pattern: self::MEASURE, message: 'Use a number of zero or more with up to four decimals.')]
        public string $min = '0',
        #[Assert\Regex(pattern: self::MEASURE, message: 'Use a number of zero or more with up to four decimals.')]
        public ?string $max = null,
    ) {}
}
