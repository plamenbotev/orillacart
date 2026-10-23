<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the EU's threshold of distance sales in the shop's currency, 10000 for €10,000.
 */
final readonly class SaveEuThreshold
{
    public function __construct(
        #[Assert\Regex('/^\d{1,12}(\.\d{1,2})?$/', message: 'Use an amount such as 10000.')]
        public string $threshold,
    ) {}
}
