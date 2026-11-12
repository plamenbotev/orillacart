<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

/** The address of the checkout page that confirms a guest's email, with the link's token. */
interface EmailConfirmationLinks
{
    public function url(#[\SensitiveParameter] string $token): string;
}
