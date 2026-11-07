<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Domain\Package;
use Symfony\Component\Validator\Constraints as Assert;

/** A new extension: its id, a name, and its files, or none to start empty. */
final readonly class InstallPackage
{
    /**
     * @param array<string, string> $files by path
     */
    public function __construct(
        #[Assert\Regex(pattern: Package::SLUG_PATTERN, message: 'An id is up to 40 lowercase letters, digits and underscores, starting with a letter.')]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: Package::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\All([new Assert\Type('string')])]
        public array $files = [],
    ) {}
}
