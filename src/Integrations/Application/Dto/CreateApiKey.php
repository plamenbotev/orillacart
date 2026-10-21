<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

use OrillaCart\Integrations\Domain\ApiKey;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateApiKey
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: ApiKey::MAX_NAME_LENGTH)]
        public string $name = '',
        #[Assert\Choice(choices: ['read', 'read_write'])]
        public string $scope = 'read',
    ) {}
}
