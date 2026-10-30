<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Newsletter\Application\ConfirmLinks;
use OrillaCart\Newsletter\Application\Newsletter;

/**
 * Asks whoever signed up for the newsletter to confirm it (double opt-in), from
 * templates/emails/newsletter-confirm.html.twig, in the language they signed up in, with the signed link that
 * confirms it. One confirmed or withdrawn since is not asked; one the mail system refused is tried again.
 */
final readonly class SendNewsletterConfirmation implements JobHandler
{
    public function __construct(
        private Newsletter $newsletter,
        private ConfirmLinks $links,
        private EmailWriter $writer,
        private Mailer $mailer,
    ) {}

    public static function type(): string
    {
        return Newsletter::CONFIRM_JOB;
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
            $locale = $job->payload['locale'] ?? null;
            if (!\is_string($email)) {
                $progress->done($job, JobOutcome::fail('The job names no email.'));
                continue;
            }
            if (!$this->newsletter->isPending($email)) {
                $progress->done($job, null);
                continue;
            }
            try {
                // Anyone can type any address: nothing they typed goes in it but the address.
                $message = $this->writer->write('emails/newsletter-confirm.html.twig', 'newsletter_confirm', $email, \is_string($locale) ? $locale : null, ['link' => $this->links->for($email)]);
            } catch (EmailNotWritten $e) {
                $progress->done($job, JobOutcome::fail($e->getMessage()));
                continue;
            }
            $progress->done($job, $this->mailer->send($message) ? null : JobOutcome::retry('The newsletter confirmation email was not sent: the mail system refused it.'));
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return 'Newsletter confirmation email';
    }
}
