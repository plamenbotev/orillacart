<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application\Dto;

use OrillaCart\Integrations\Domain\Webhook;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveWebhook
{
    /**
     * @param list<string> $topics
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Webhook::MAX_NAME_LENGTH)]
        public string $name = '',
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        #[Assert\Length(max: 500)]
        public string $url = '',
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('string')])]
        public array $topics = [],
        public bool $active = true,
    ) {}
}
