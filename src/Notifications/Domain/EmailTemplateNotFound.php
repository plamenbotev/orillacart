<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class EmailTemplateNotFound extends NotFound
{
    public function __construct(string $name)
    {
        parent::__construct(\sprintf('There is no email template "%s".', $name), 'orillacart_email_template_not_found');
    }
}
