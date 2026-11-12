<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Checkout\Application\EmailConfirmationLinks;
use OrillaCart\Checkout\Application\EmailConfirmations;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;

/**
 * Emails a guest the link that confirms their email for ordering (EmailConfirmations::send()), from
 * templates/emails/email-confirmation.html.twig, in the language they order in. One the mail system refused is
 * tried again.
 */
final readonly class SendEmailConfirmation implements JobHandler
{
    public const string KIND = 'email_confirmation';

    public function __construct(
        private EmailConfirmationLinks $links,
        private EmailWriter $writer,
        private Mailer $mailer,
    ) {}

    public static function type(): string
    {
        return EmailConfirmations::LINK_JOB;
    }

    public static function queue(): string
    {
        return 'mail';
    }

    public function maxAttempts(): int
    {
        return 5;
    }

    public function retryAfter(int $attempt): int
    {
        return 60 * 4 ** ($attempt - 1);
    }

    public function lockSeconds(): int
    {
        return 120;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        foreach ($jobs as $job) {
            $email = $job->payload['email'] ?? null;
            $token = $job->payload['token'] ?? null;
            $locale = $job->payload['locale'] ?? null;
            if (!\is_string($email) || !\is_string($token)) {
                $progress->done($job, JobOutcome::fail('The job names no email or link.'));
                continue;
            }
            try {
                $message = $this->writer->write('emails/email-confirmation.html.twig', self::KIND, $email, \is_string($locale) ? $locale : null, fn(): array => ['link' => $this->links->url($token), 'hours' => EmailConfirmations::VALID_HOURS]);
            } catch (EmailNotWritten $e) {
                $progress->done($job, JobOutcome::fail($e->getMessage()));
                continue;
            }
            $progress->done($job, $this->mailer->send($message) ? null : JobOutcome::retry('The link to confirm the email was not sent: the mail system refused it.'));
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return 'Email confirmation link for an order';
    }
}
