<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

/**
 * Whether the customer pays with their store credit.
 */
final readonly class UseStoreCredit
{
    public function __construct(public bool $use = true) {}
}
