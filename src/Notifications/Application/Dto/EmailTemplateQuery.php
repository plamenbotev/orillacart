<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: which language's template, the one for every language when none is given.
 */
final readonly class EmailTemplateQuery
{
    public function __construct(
        /** The site's language, a WordPress locale such as de_DE; empty for every language. */
        #[Assert\Regex(pattern: '/^([a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2})?$/')]
        public string $locale = '',
    ) {}
}
