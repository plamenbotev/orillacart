<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Ordering\Domain\OrderNotFound;

/**
 * Sends the order emails queued by OrderEmails. A mail the mail system refused is tried again after 1, 4, 16 and
 * 64 minutes, then failed for the shop to look at; one whose template fails is failed at once.
 */
final readonly class SendOrderEmails implements JobHandler
{
    public const string TYPE = 'mail.order';

    public function __construct(private OrderEmails $emails) {}

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

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $kind = EmailKind::tryFrom(\is_string($job->payload['kind'] ?? null) ? $job->payload['kind'] : '');
            $order = $job->payload['order'] ?? null;
            if ($kind === null || !\is_int($order)) {
                $outcomes[$job->id] = JobOutcome::fail('The job does not name an order and a kind of email.');
                continue;
            }
            try {
                $this->emails->send($order, $kind, \is_string($job->payload['note'] ?? null) ? $job->payload['note'] : '', self::refund($job->payload['refund'] ?? null));
            } catch (OrderNotFound) {
                // Deleted since: nobody to write to about it.
            } catch (EmailNotWritten $e) {
                $outcomes[$job->id] = JobOutcome::fail($e->getMessage());
            } catch (EmailNotSent $e) {
                $outcomes[$job->id] = JobOutcome::retry($e->getMessage());
            }
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $kind = EmailKind::tryFrom(\is_string($payload['kind'] ?? null) ? $payload['kind'] : '');

        return \sprintf('Order #%s: %s email', \is_int($payload['order'] ?? null) ? $payload['order'] : '?', $kind === null ? 'unknown' : str_replace('_', ' ', $kind->value));
    }

    /**
     * @return array{amount: string, full: bool, quantities: list<array{line: int, quantity: int}>}|null
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

        return ['amount' => $refund['amount'], 'full' => ($refund['full'] ?? false) === true, 'quantities' => $quantities];
    }
}
