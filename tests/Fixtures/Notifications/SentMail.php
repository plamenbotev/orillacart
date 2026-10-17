<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Mailer;

/**
 * Keeps every email instead of sending it.
 */
final class SentMail implements Mailer
{
    /** @var list<Email> */
    public array $emails = [];
    public bool $accepts = true;

    public function send(Email $email): bool
    {
        $this->emails[] = $email;

        return $this->accepts;
    }

    /** @return list<string> "to: subject" of each email */
    public function summary(): array
    {
        return array_map(static fn(Email $email): string => "{$email->to}: {$email->subject}", $this->emails);
    }
}
