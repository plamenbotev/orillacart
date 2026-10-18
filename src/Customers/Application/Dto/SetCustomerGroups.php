<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the groups a customer is in, all of them.
 */
final readonly class SetCustomerGroups
{
    /**
     * @param list<int> $groupIds
     */
    public function __construct(
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Positive()])]
        public array $groupIds = [],
    ) {}
}
