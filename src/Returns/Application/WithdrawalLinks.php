<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

/** The address of the withdrawal page that opens an order's withdrawal, with the link's token. */
interface WithdrawalLinks
{
    public function url(#[\SensitiveParameter] string $token): string;
}
