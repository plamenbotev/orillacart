<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a bundle with the customer's choices, as the product page shows it while they choose.
 */
final readonly class ChosenBundle
{
    /**
     * @param list<int> $bundleChoices the variations chosen and the optional products added
     */
    public function __construct(
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Positive()])]
        public array $bundleChoices = [],
    ) {}
}
