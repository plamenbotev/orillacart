<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Returns\Domain\Event\WithdrawalDeclared;
use OrillaCart\Returns\Domain\ReturnRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells of a withdrawal once it is saved (Returns::withdraw()): the WithdrawalDeclared event, which the
 * acknowledgement email answers, goes out from here, in a job of the mail lane, not from inside the order's lock.
 * The job keeps what the consumer declared; the order and the return say the rest, in the customer's words.
 */
final readonly class SendWithdrawalAcknowledgements implements JobHandler
{
    public const string TYPE = 'mail.withdrawal';

    public function __construct(
        private ReturnRepository $returns,
        private OrderService $orders,
        private EventDispatcherInterface $events,
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

    public function lockSeconds(): int
    {
        return 600;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        foreach ($jobs as $job) {
            $orderId = $job->payload['order'] ?? null;
            $returnId = $job->payload['return'] ?? null;
            if (!\is_int($orderId) || !\is_int($returnId)) {
                $progress->done($job, JobOutcome::fail('The job does not name an order and a return.'));
                continue;
            }
            $return = $this->returns->find($returnId);
            if ($return === null) {
                $progress->done($job);
                continue;
            }
            try {
                $order = $this->orders->inCustomerWords($orderId);
            } catch (OrderNotFound) {
                // Deleted since: nobody to write to about it.
                $progress->done($job);
                continue;
            }
            $listed = [];
            foreach ($return->items() as $line => $quantity) {
                $listed[] = ['name' => $order->lines[$line]->name ?? '', 'quantity' => $quantity];
            }
            try {
                $this->events->dispatch(new WithdrawalDeclared(
                    $orderId,
                    $returnId,
                    \is_string($job->payload['name'] ?? null) ? $job->payload['name'] : '',
                    \is_string($job->payload['confirmation'] ?? null) ? $job->payload['confirmation'] : $order->email,
                    $order->email,
                    $listed,
                    \is_string($job->payload['declared_at'] ?? null) ? $job->payload['declared_at'] : $return->requestedAt()->format(\DATE_ATOM),
                    $order->locale,
                ));
                $progress->done($job);
            } catch (\Throwable $e) {
                $this->logger->error('The acknowledgement of the withdrawal from order {order} failed: {message}', ['order' => $orderId, 'message' => $e->getMessage(), 'exception' => $e]);
                $progress->done($job, JobOutcome::retry($e->getMessage()));
            }
        }

        return [];
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        return \sprintf('Order #%s: withdrawal acknowledgement email', \is_int($payload['order'] ?? null) ? $payload['order'] : '?');
    }
}
