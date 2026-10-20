<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class ReturnNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct(\sprintf('There is no return %d.', $id), 'orillacart_return_not_found');
    }
}
