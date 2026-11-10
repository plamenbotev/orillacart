<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class InvitationNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('There is no invitation %d.', 'orillacart_invitation_not_found', values: [$id]);
    }
}
