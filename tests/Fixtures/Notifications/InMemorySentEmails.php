<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Domain\SentEmail;
use OrillaCart\Notifications\Domain\SentEmails;

final class InMemorySentEmails implements SentEmails
{
    /** @var array<string, SentEmail> */
    public array $sent = [];

    public function wasSent(string $job): bool
    {
        return isset($this->sent[$job]);
    }

    public function remember(SentEmail $sent): void
    {
        $this->sent[$sent->job()] = $sent;
    }

    public function forgetBefore(\DateTimeImmutable $before): int
    {
        $kept = array_filter($this->sent, static fn(SentEmail $sent): bool => $sent->sentAt() >= $before);
        $forgotten = \count($this->sent) - \count($kept);
        $this->sent = $kept;

        return $forgotten;
    }
}
