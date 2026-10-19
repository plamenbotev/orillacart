<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: search with the database, or with OpenSearch at this address. An empty password keeps the saved one,
 * unless clear_password says to forget it.
 */
final readonly class ChangeSearchEngine
{
    public function __construct(
        #[Assert\Choice(choices: ['database', 'opensearch'])]
        public string $engine,
        #[Assert\Length(max: 300)]
        public string $host = '',
        #[Assert\Length(max: 200)]
        public string $username = '',
        #[Assert\Length(max: 200)]
        public string $password = '',
        #[Assert\Length(max: 100)]
        public string $index = 'orillacart',
        public bool $clearPassword = false,
    ) {}
}
