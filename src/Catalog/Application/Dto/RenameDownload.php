<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\DownloadFile;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameDownload
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: DownloadFile::MAX_NAME_LENGTH)]
        public string $name,
    ) {}
}
