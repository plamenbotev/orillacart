<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class GiftCardNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('There is no gift card %d.', 'orillacart_gift_card_not_found', values: [$id]);
    }
}
