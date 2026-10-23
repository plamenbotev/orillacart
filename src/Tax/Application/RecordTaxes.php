<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Extension\Tax\TaxAddress as ExtensionTaxAddress;
use OrillaCart\Extension\Tax\TaxedOrder;
use OrillaCart\Extension\Tax\TaxLine;
use OrillaCart\Extension\Tax\TaxRefund;
use OrillaCart\Extension\Tax\TaxRequestLine;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Jobs\Application\JobHandler;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Tells the tax service that taxed an order what became of it, in the background: the sale once it is paid
 * (keeping the service's reference), the tax in each refund, and a recorded order cancelled. A service that fails
 * is tried again later, then the job is failed for the shop to look at.
 */
final readonly class RecordTaxes implements JobHandler
{
    public const string TYPE = 'tax.record';
    public const string COMMIT = 'commit';
    public const string REFUND = 'refund';
    public const string VOID = 'void';

    public function __construct(
        private OrderService $orders,
        private TaxServices $services,
        private SettingsService $settings,
        private ClockInterface $clock,
    ) {}

    public static function type(): string
    {
        return self::TYPE;
    }

    public static function queue(): string
    {
        return 'tax';
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

    public function handle(array $jobs): array
    {
        $outcomes = [];
        foreach ($jobs as $job) {
            $outcomes[$job->id] = $this->run($job);
        }

        return $outcomes;
    }

    public function describe(array $payload, ?string $uniqueKey): string
    {
        $order = $payload['order'] ?? '?';

        return \sprintf('Order #%s: %s with the tax service', \is_scalar($order) ? (string) $order : '?', match ($payload['action'] ?? null) {
            self::REFUND => 'a refund reported',
            self::VOID => 'the sale voided',
            default => 'the sale recorded',
        });
    }

    private function run(QueuedJob $job): JobOutcome
    {
        $number = $job->payload['order'] ?? null;
        $action = $job->payload['action'] ?? null;
        if (!\is_int($number)) {
            return JobOutcome::fail('The job names no order.');
        }
        try {
            $order = $this->orders->order($number);
        } catch (OrderNotFound) {
            return JobOutcome::done();
        }
        if ($order->taxProvider === null || ($action === self::COMMIT && $order->taxReference !== null)) {
            return JobOutcome::done();
        }
        $provider = $this->services->provider($order->taxProvider);
        if ($provider === null) {
            return JobOutcome::fail(\sprintf('The tax service %s is not installed or not on.', $order->taxProvider));
        }
        if ($action === self::VOID && $order->taxReference === null) {
            // Never recorded: nothing to void.
            return JobOutcome::done();
        }
        if ($action === self::REFUND && $order->taxReference === null) {
            // The sale is recorded first: a refund waits for it.
            return JobOutcome::retry('The sale is not recorded with the tax service yet.');
        }
        $taxed = $this->taxed($order);
        try {
            match ($action) {
                self::COMMIT => $this->orders->taxRecorded($number, $provider->id(), $provider->commit($taxed)),
                self::REFUND => $provider->refund($taxed, new TaxRefund(
                    self::text($job->payload['amount'] ?? '0'),
                    self::text($job->payload['tax'] ?? '0'),
                    $this->clock->now()->format('Y-m-d'),
                    ($job->payload['full'] ?? false) === true,
                )),
                self::VOID => $provider->void($taxed),
                default => throw new \UnexpectedValueException(\sprintf('"%s" is not something to tell a tax service.', \is_scalar($action) ? (string) $action : '?')),
            };
        } catch (\UnexpectedValueException $e) {
            return JobOutcome::fail($e->getMessage());
        } catch (\Throwable $e) {
            return JobOutcome::retry($e->getMessage());
        }

        return JobOutcome::done();
    }

    private function taxed(OrderView $order): TaxedOrder
    {
        $lines = [];
        $taxes = [];
        $counts = [];
        foreach ([...$order->lines, ...$order->charges] as $line) {
            $number = $counts[$line->kind] = ($counts[$line->kind] ?? -1) + 1;
            $id = $line->kind === 'shipping' ? 'shipping' : "{$line->kind}:{$number}";
            $lines[] = new TaxRequestLine($id, $line->kind, $line->name, null, $line->quantity, self::net($line), $line->kind === 'item' ? $line->productId : null, $line->sku);
            $taxes[$id] = array_map(static fn(LineTaxView $tax): TaxLine => new TaxLine($tax->name, $tax->percent, $tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction, $tax->taxable, $tax->amount, $tax->exempt), $line->taxes);
        }
        $shop = $this->settings->current();
        $to = $order->shippingAddress ?? $order->billing;

        return new TaxedOrder(
            $order->number,
            $order->currency,
            new \DateTimeImmutable($order->placedAt)->format('Y-m-d'),
            new ExtensionTaxAddress($shop->shopCountry ?? '', $shop->shopState, $shop->shopPostcode ?? ''),
            new ExtensionTaxAddress($to->country, $to->state, $to->postcode, $to->city, $to->street),
            $lines,
            $taxes,
            $order->customerId,
            $order->taxExemption['certificate'] ?? null,
            $order->taxReference,
        );
    }

    /** What the line costs before tax, after the changes to its price. */
    private static function net(OrderLineView $line): string
    {
        return (string) Money::of($line->total)->subtract(Money::of($line->discount));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '0';
    }
}
