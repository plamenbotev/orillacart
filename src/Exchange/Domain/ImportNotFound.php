<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class ImportNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('There is no import %d.', 'orillacart_import_not_found', values: [$id]);
    }
}
