<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** An extension as a file: what export gives and import takes. Settings and permissions are not part of it. */
final readonly class PackageExport
{
    /**
     * @param array<string, string>                                    $files        by path
     * @param array<string, array<string, list<string>|string>> $translations its words' translations by word key and locale, the forms as a list (design 57)
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $id,
        #[Assert\NotBlank]
        public string $name,
        public string $version = '',
        #[Assert\All([new Assert\Type('string')])]
        public array $files = [],
        public string $format = 'orillacart-extension/1',
        public array $translations = [],
    ) {}
}
