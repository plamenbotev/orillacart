<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\SentEmail;
use OrillaCart\Notifications\Domain\SentEmails;
use OrillaCart\Ordering\Domain\OrderNotFound;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the order emails queued by OrderEmails. A mail the mail system refused is tried again after 1, 4, 16 and
 * 64 minutes, then failed for the shop to look at; one whose template fails is failed at once. Each job is
 * reported as its mail went out and marked sent, and anything that goes wrong stays with its job: a job run again
 * (its worker stopped, or the batch was retried) never sends a mail twice.
 */
final readonly class SendOrderEmails implements JobHandler
{
    public const string TYPE = 'mail.order';
    /** How long sent jobs are remembered: longer than any job waits or is retried. */
    public const string REMEMBER_FOR = 'P30D';

    public function __construct(
        private OrderEmails $emails,
        private SentEmails $sent,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
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

    /** One mail, a while with a slow mail server; the hold is extended as the batch goes on. */
    public function lockSeconds(): int
    {
        return 300;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        // Each mail is settled as it goes out: a worker killed mid-batch leaves one job to do again, not the batch.
        foreach ($jobs as $job) {
            $progress->done($job, $this->send($job));
        }

        return [];
    }

    /** One job's mail. */
    private function send(QueuedJob $job): JobOutcome
    {
        $kind = EmailKind::tryFrom(\is_string($job->payload['kind'] ?? null) ? $job->payload['kind'] : '');
        $order = $job->payload['order'] ?? null;
        if ($kind === null || !\is_int($order)) {
            return JobOutcome::fail('The job does not name an order and a kind of email.');
        }
        $mark = "{$job->id}:{$job->version}";
        try {
            if ($this->sent->wasSent($mark)) {
                return JobOutcome::done();
            }
            $this->emails->send($order, $kind, \is_int($job->payload['note'] ?? null) ? $job->payload['note'] : null, self::refund($job->payload['refund'] ?? null));
            $this->sent->remember(new SentEmail($mark, $this->clock->now()));
        } catch (OrderNotFound) {
            // Deleted since: nobody to write to about it.
        } catch (EmailNotWritten $e) {
            return JobOutcome::fail($e->getMessage());
        } catch (EmailNotSent $e) {
            return JobOutcome::retry($e->getMessage());
        } catch (\Throwable $e) {
            // Anything else (the database, an add-on's filter): this job tries again, the others went out.
            $this->logger->error('The {kind} email of order {order} failed: {message}', ['kind' => $kind->value, 'order' => $order, 'message' => $e->getMessage(), 'exception' => $e]);

            return JobOutcome::retry($e->getMessage());
        }

        return JobOutcome::done();
    }

    /**
     * @return int how many sent jobs were forgotten
     */
    public function forgetSent(): int
    {
        return $this->sent->forgetBefore($this->clock->now()->sub(new \DateInterval(self::REMEMBER_FOR)));
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $kind = EmailKind::tryFrom(\is_string($payload['kind'] ?? null) ? $payload['kind'] : '');

        return \sprintf('Order #%s: %s email', \is_int($payload['order'] ?? null) ? $payload['order'] : '?', $kind === null ? 'unknown' : str_replace('_', ' ', $kind->value));
    }

    /**
     * @return array{amount: string, full: bool, quantities: list<array{line: int, quantity: int}>, credit?: string|null}|null
     */
    private static function refund(mixed $refund): ?array
    {
        if (!\is_array($refund) || !\is_string($refund['amount'] ?? null)) {
            return null;
        }
        $quantities = [];
        foreach (\is_array($refund['quantities'] ?? null) ? $refund['quantities'] : [] as $line) {
            if (\is_array($line) && \is_int($line['line'] ?? null) && \is_int($line['quantity'] ?? null)) {
                $quantities[] = ['line' => $line['line'], 'quantity' => $line['quantity']];
            }
        }

        return ['amount' => $refund['amount'], 'full' => ($refund['full'] ?? false) === true, 'quantities' => $quantities, 'credit' => \in_array($refund['credit'] ?? null, ['store_credit', 'gift_card'], true) ? $refund['credit'] : null];
    }
}
