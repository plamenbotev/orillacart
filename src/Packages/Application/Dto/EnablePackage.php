<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The permissions the manager grants when switching the extension on; those it declared must be among them. */
final readonly class EnablePackage
{
    /**
     * @param list<string> $grant
     */
    public function __construct(
        #[Assert\All([new Assert\Regex(pattern: '/^[a-z_]+(:[a-z_]+)?$/')])]
        public array $grant = [],
    ) {}
}
