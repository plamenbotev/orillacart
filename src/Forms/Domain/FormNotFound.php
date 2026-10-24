<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class FormNotFound extends NotFound
{
    public function __construct(public readonly int $formId)
    {
        parent::__construct('There is no form %d.', 'orillacart_form_not_found', values: [$formId]);
    }
}
