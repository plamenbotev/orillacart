<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\NexusThresholds;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the economic nexus thresholds the shop goes by, for every US state and for those whose own differ.
 */
final readonly class SaveNexusThresholds
{
    /**
     * @param list<SaveNexusThreshold> $states each with its state code
     */
    public function __construct(
        #[Assert\Valid]
        public SaveNexusThreshold $everywhere,
        #[Assert\Count(max: NexusThresholds::MAX_STATES)]
        #[Assert\Valid]
        public array $states = [],
    ) {}
}
