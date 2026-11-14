<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

/**
 * The address the shop's email goes out from, which an e-invoice names when the seller gave none of its own.
 * Notifications, which sends the email, keeps it.
 */
interface ShopEmail
{
    public function shopAddress(): string;
}
