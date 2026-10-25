<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ApplySetup
{
    /**
     * @param list<string> $replace the keys of changes to make over what there is
     */
    public function __construct(
        #[Assert\Regex('/^[0-9a-f]{64}$/')]
        public string $fingerprint,
        #[Assert\Count(max: 500)]
        #[Assert\All([new Assert\Length(max: 100)])]
        public array $replace = [],
    ) {}
}
