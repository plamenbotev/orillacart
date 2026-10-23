<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the import schemes the shop sells low-value goods under; the others are left as they were before
 * (no rule of their own).
 */
final readonly class SaveImportSchemes
{
    public function __construct(
        /** @var list<SaveImportRegistration> */
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $schemes = [],
    ) {}
}
