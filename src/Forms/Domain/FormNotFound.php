<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class FormNotFound extends NotFound
{
    public function __construct(public readonly int $formId)
    {
        parent::__construct(\sprintf('There is no form %d.', $formId), 'orillacart_form_not_found');
    }
}
