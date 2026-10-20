<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use OrillaCart\Notifications\Domain\EmailTemplate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: use this source for an email template, in place of its file.
 */
final readonly class SaveEmailTemplate
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: EmailTemplate::MAX_LENGTH)]
        public string $source,
    ) {}
}
