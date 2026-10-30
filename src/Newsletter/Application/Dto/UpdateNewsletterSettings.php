<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application\Dto;

use OrillaCart\Newsletter\Domain\Subscriber;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateNewsletterSettings
{
    /**
     * @param list<string> $lists the ids of the mailing lists the sign-ups go to
     */
    public function __construct(
        public bool $offered = false,
        #[Assert\NotBlank]
        #[Assert\Length(max: Subscriber::MAX_CONSENT_LENGTH)]
        public string $consent = '',
        #[Assert\All([new Assert\Type('string')])]
        public array $lists = [],
        /** Whether a new sign-up counts only once confirmed from the email (double opt-in). */
        public bool $confirmNew = true,
    ) {}
}
