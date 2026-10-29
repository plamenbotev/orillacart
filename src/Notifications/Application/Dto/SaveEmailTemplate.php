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
        /** The site's language it is for, a WordPress locale such as de_DE; empty for every language. */
        #[Assert\Regex(pattern: '/^([a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2})?$/')]
        public string $locale = '',
    ) {}
}
