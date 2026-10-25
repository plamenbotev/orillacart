<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * Whether the shop is open, or shows visitors "Opening soon" while it is set up.
 */
interface OpeningRepository
{
    public function opensSoon(): bool;

    public function save(bool $opensSoon): void;
}
