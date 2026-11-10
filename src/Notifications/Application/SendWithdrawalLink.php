<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Returns\Application\WithdrawalLinks;
use OrillaCart\Returns\Application\WithdrawalRequests;

/**
 * Emails an order's own address the link that opens its withdrawal (WithdrawalRequests::ask()), from
 * templates/emails/withdrawal-link.html.twig, in the order's language. A test order is never emailed; one the mail
 * system refused is tried again.
 */
final readonly class SendWithdrawalLink implements JobHandler
{
    public const string KIND = 'withdrawal_link';

    public function __construct(
        private OrderService $orders,
        private WithdrawalLinks $links,
        private EmailWriter $writer,
        private Mailer $mailer,
    ) {}

    public static function type(): string
    {
        return WithdrawalRequests::LINK_JOB;
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
            $number = $job->payload['order'] ?? null;
            $token = $job->payload['token'] ?? null;
            if (!\is_int($number) || !\is_string($token)) {
                $progress->done($job, JobOutcome::fail('The job names no order or link.'));
                continue;
            }
            try {
                $order = $this->orders->order($number);
            } catch (OrderNotFound) {
                $progress->done($job, null);
                continue;
            }
            if ($order->test) {
                $progress->done($job, null);
                continue;
            }
            $askedAt = explode('.', $token)[1] ?? '';
            try {
                $message = $this->writer->write('emails/withdrawal-link.html.twig', self::KIND, $order->email, $order->locale, ['number' => $order->number, 'link' => $this->links->url($token), 'asked_at' => is_numeric($askedAt) ? gmdate(\DATE_ATOM, (int) $askedAt) : ''], null, $order->number);
            } catch (EmailNotWritten $e) {
                $progress->done($job, JobOutcome::fail($e->getMessage()));
                continue;
            }
            $progress->done($job, $this->mailer->send($message) ? null : JobOutcome::retry('The withdrawal link was not sent: the mail system refused it.'));
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \is_int($payload['order'] ?? null) ? \sprintf('Order #%d: withdrawal link email', $payload['order']) : 'Withdrawal link email';
    }
}
