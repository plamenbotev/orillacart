<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Extension\Invoice\EInvoiceFile;
use OrillaCart\Extension\Invoice\EInvoiceSender;
use OrillaCart\Invoicing\Domain\InvoiceNotFound;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Jobs\Application\JobProgress;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Shared\Extension\InstalledExtensions;

/**
 * Hands each invoice and credit note, once issued, to the add-ons that send e-invoices on (a Peppol access point,
 * a country's platform), one job for each, in the background. A sender that fails is tried again later, then the
 * job is failed for the shop to look at.
 */
final readonly class SendEInvoices implements JobHandler
{
    public const string TYPE = 'invoice.send';

    public function __construct(
        private Invoices $invoices,
        private EInvoices $eInvoices,
        private InstalledExtensions $extensions,
        private Jobs $jobs,
    ) {}

    /** Queues the document for every sender, when the shop makes e-invoices and an add-on sends them. */
    public function forDocument(string $number): void
    {
        if ($this->eInvoices->format() === null) {
            return;
        }
        $this->jobs->pushMany(self::TYPE, array_map(static fn(EInvoiceSender $sender): array => [
            'key' => "{$number}:{$sender->id()}",
            'payload' => ['number' => $number, 'sender' => $sender->id()],
        ], $this->extensions->eInvoiceSenders()));
    }

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'invoices';
    }

    public function maxAttempts(): int
    {
        return 8;
    }

    public function retryAfter(int $attempt): int
    {
        return 60 * 2 ** ($attempt - 1);
    }

    public function lockSeconds(): int
    {
        return 120;
    }

    public function handle(array $jobs, JobProgress $progress): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $outcomes[$job->id] = $this->run($job);
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $number = $payload['number'] ?? '?';
        $sender = $payload['sender'] ?? '?';

        return \sprintf('%s sent on by %s', \is_string($number) ? $number : '?', \is_string($sender) ? $sender : '?');
    }

    private function run(QueuedJob $job): JobOutcome
    {
        $number = $job->payload['number'] ?? null;
        $id = $job->payload['sender'] ?? null;
        if (!\is_string($number) || !\is_string($id)) {
            return JobOutcome::fail('The job names no document or sender.');
        }
        $sender = array_find($this->extensions->eInvoiceSenders(), static fn(EInvoiceSender $sender): bool => $sender->id() === $id);
        if ($sender === null) {
            return JobOutcome::fail(\sprintf('The e-invoice sender %s is not installed or not on.', $id));
        }
        $format = $this->eInvoices->format();
        if ($format === null) {
            return JobOutcome::done();
        }
        try {
            $document = $this->invoices->issued($number);
        } catch (InvoiceNotFound) {
            return JobOutcome::done();
        }
        $order = $document->order;
        try {
            $sender->send(new EInvoiceFile(
                $number,
                $document->invoice->kind === EInvoiceFile::CREDIT_NOTE ? EInvoiceFile::CREDIT_NOTE : EInvoiceFile::INVOICE,
                $order->number,
                $format,
                $this->eInvoices->filename($document),
                $this->eInvoices->xml($document, $format),
                $order->email,
                $order->billing->country,
                $order->vatNumber,
                $order->buyerReference,
                $this->eInvoices->peppolRecipient($document, $format),
            ));
        } catch (\Throwable $e) {
            return JobOutcome::retry($e->getMessage());
        }

        return JobOutcome::done();
    }
}
