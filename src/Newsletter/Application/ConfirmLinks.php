<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application;

/** The address that confirms a sign-up, signed for its email alone. */
interface ConfirmLinks
{
    public function for(string $email): string;
}
