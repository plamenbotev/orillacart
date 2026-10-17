<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Application\Dto\Email;

interface Mailer
{
    /** @return bool whether the mail system accepted it */
    public function send(Email $email): bool;
}
