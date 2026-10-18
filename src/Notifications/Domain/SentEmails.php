<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

interface SentEmails
{
    public function wasSent(string $job): bool;

    /** Saved at once, outside any transaction: the mail is gone whatever happens next. */
    public function remember(SentEmail $sent): void;

    /** @return int how many were forgotten */
    public function forgetBefore(\DateTimeImmutable $before): int;
}
