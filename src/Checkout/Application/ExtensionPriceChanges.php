<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Extension\Pricing\AdjustedLine;
use OrillaCart\Extension\Pricing\Adjustment;
use OrillaCart\Extension\Pricing\AdjustmentQuery;
use OrillaCart\Ordering\Application\Dto\AdjustmentView;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * What the extensions' price adjusters change of an order's items, spread over the lines it covers. An adjuster
 * that fails changes nothing, and the shop hears of it.
 *
 * @phpstan-import-type PricedLine from OrderPricing
 */
final readonly class ExtensionPriceChanges
{
    public function __construct(
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private ExtensionFailures $failures,
    ) {}

    /**
     * The changes to the items' prices besides coupons, by item: the shop's and add-ons' given, and what the
     * add-ons' order adjusters make now. One for the whole order is spread over the items by what they cost after
     * the coupons, rounded to the currency so they add up to it.
     *
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     * @param list<PricedLine> $lines
     * @param array<string, string> $fields
     *
     * @return array<int, list<AdjustmentView>>
     *
     * @throws CheckoutRuleViolation for a change to a line the order does not have, or an adjuster that failed closed
     */
    public function of(array $changes, array $lines, int $decimals, string $currency, ?int $customerId, ?string $email, ?int $orderId, array $fields = []): array
    {
        if ($lines === []) {
            return [];
        }
        $nets = array_map(static fn(array $line): Money => $line['total']->subtract($line['discount']), $lines);
        foreach ($this->extensions->orderAdjusters() as $adjuster) {
            $query = new AdjustmentQuery(
                array_map(static fn(int $index, array $line): AdjustedLine => new AdjustedLine($index, $line['quote']->productId, $line['quote']->variationId, $line['quote']->name, $line['quantity'], (string) $nets[$index]), array_keys($lines), $lines),
                $currency,
                $customerId,
                $email,
                $orderId,
                $fields,
            );
            try {
                $made = $adjuster->adjust($query);
            } catch (\Throwable $e) {
                $this->logger->error('The order adjuster {adjuster} failed: {message}', ['adjuster' => $adjuster->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                if ($adjuster instanceof FailsClosed) {
                    throw new CheckoutRuleViolation('The prices could not be worked out just now. Try again in a moment.', 'orillacart_adjuster_failed');
                }
                $this->failures->record(\sprintf('The order adjuster %s failed; the order was priced without it.', $adjuster->id()), false);
                continue;
            }
            foreach ($made as $adjustment) {
                if (!$adjustment instanceof Adjustment || preg_match('/^-?\d{1,11}(\.\d{1,4})?$/', $adjustment->amount) !== 1 || ($adjustment->line !== null && !isset($lines[$adjustment->line])) || trim($adjustment->label) === '') {
                    $this->logger->error('The order adjuster {adjuster} answered an adjustment that cannot be; it is left out.', ['adjuster' => $adjuster->id()]);
                    continue;
                }
                $changes[] = ['kind' => PriceAdjustment::ADJUSTER, 'source' => $adjuster->id(), 'label' => trim($adjustment->label), 'amount' => $adjustment->amount, 'line' => $adjustment->line, 'reason' => trim($adjustment->reason) === '' ? null : trim($adjustment->reason), 'change' => null];
            }
        }
        $adjusted = [];
        foreach ($changes as $change) {
            $amount = Money::of($change['amount']);
            $key = $change['change'] ?? PriceAdjustment::newChange();
            if ($change['line'] !== null) {
                if (!isset($lines[$change['line']])) {
                    throw new CheckoutRuleViolation('The order has no such line to change the price of.', 'orillacart_invalid_price_change');
                }
                $adjusted[$change['line']][] = new AdjustmentView($change['kind'], $change['source'], $change['label'], (string) $amount->rounded($decimals), PriceAdjustment::LINE, false, $change['reason'], change: $key);
                continue;
            }
            foreach (PriceShares::spread($amount, $nets, $decimals) as $index => $share) {
                if (!$share->isZero()) {
                    $adjusted[$index][] = new AdjustmentView($change['kind'], $change['source'], $change['label'], (string) $share, PriceAdjustment::ORDER, false, $change['reason'], change: $key);
                }
            }
        }

        return $adjusted;
    }
}
