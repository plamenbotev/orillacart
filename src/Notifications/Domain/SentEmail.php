<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An email job whose mail went out: a job run again, because its worker stopped or its batch failed after the
 * mail system took the email, does not send it twice. Or an email an order gets once, as its payment reminder
 * ("payment_reminder:ORDER"), from when it was queued. Kept for a month.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sent_emails')]
#[ORM\Index(name: 'sent_email_at', columns: ['sent_at'])]
class SentEmail
{
    public function __construct(
        /** The job and its version: a job pushed again while it waited is new news, and sends again. Or the once-only email's mark. */
        #[ORM\Id]
        #[ORM\Column(length: 80)]
        private string $job,
        #[ORM\Column]
        private \DateTimeImmutable $sentAt,
    ) {}

    public function job(): string
    {
        return $this->job;
    }

    public function sentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
