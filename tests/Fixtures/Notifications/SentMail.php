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
    /** Whether the mail system takes emails; one it refuses is not kept. */
    public bool $accepts = true;
    /** A recipient whose emails break the mail system with an error, as an add-on's filter might. */
    public ?string $breaksFor = null;

    public function send(Email $email): bool
    {
        if ($this->breaksFor !== null && $email->to === $this->breaksFor) {
            throw new \RuntimeException('The mail system broke.');
        }
        if ($this->accepts) {
            $this->emails[] = $email;
        }

        return $this->accepts;
    }

    /** @return list<string> "to: subject" of each email */
    public function summary(): array
    {
        return array_map(static fn(Email $email): string => "{$email->to}: {$email->subject}", $this->emails);
    }
}
