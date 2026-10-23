<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\Doctrine;

use OrillaCart\Reports\Application\ReportStore;
use OrillaCart\Reports\Domain\CollectedTax;
use OrillaCart\Reports\Domain\GivenRefund;
use OrillaCart\Reports\Domain\OrderFacts;
use OrillaCart\Reports\Domain\SoldLine;
use OrillaCart\Reports\Domain\UsedCoupon;

/**
 * Writes a batch of orders' facts in a few statements: one delete per table for the whole batch, then multi-row
 * inserts, all in one transaction, so a report never sees an order half written.
 */
final readonly class DbalReportStore implements ReportStore
{
    private const int CHUNK = 500;
    private const string DAY = 'Y-m-d';
    private const string MOMENT = 'Y-m-d H:i:s';

    public function __construct(private ReportTables $tables) {}

    public function replace(array $orderIds, array $facts): void
    {
        if ($orderIds === []) {
            return;
        }
        $connection = $this->tables->connection();
        $connection->transactional(function () use ($connection, $orderIds, $facts): void {
            foreach (array_chunk($orderIds, self::CHUNK) as $ids) {
                $in = implode(', ', array_fill(0, \count($ids), '?'));
                foreach ([$this->tables->orders(), $this->tables->lines(), $this->tables->coupons(), $this->tables->refunds(), $this->tables->taxes()] as $table) {
                    $connection->executeStatement("DELETE FROM {$table} WHERE order_id IN ({$in})", $ids);
                }
            }
            $this->insert($this->tables->orders(), ['order_id', 'day', 'placed_at', 'counted', 'status', 'currency', 'customer_key', 'customer_id', 'customer_name', 'country', 'payment', 'shipping', 'items', 'gross', 'discount', 'shipping_total', 'tax', 'total'], array_map(static fn(OrderFacts $facts): array => [
                $facts->order->orderId,
                $facts->order->day->format(self::DAY),
                $facts->order->placedAt->format(self::MOMENT),
                (int) $facts->order->counted,
                $facts->order->status,
                $facts->order->currency,
                $facts->order->customerKey,
                $facts->order->customerId,
                $facts->order->customerName,
                $facts->order->country,
                $facts->order->payment,
                $facts->order->shipping,
                $facts->order->items,
                $facts->order->gross,
                $facts->order->discount,
                $facts->order->shippingTotal,
                $facts->order->tax,
                $facts->order->total,
            ], $facts));
            $this->insert($this->tables->lines(), ['order_id', 'day', 'counted', 'currency', 'product_id', 'variation_id', 'name', 'quantity', 'net', 'refunded'], array_map(static fn(SoldLine $line): array => [
                $line->orderId, $line->day->format(self::DAY), (int) $line->counted, $line->currency, $line->productId, $line->variationId, $line->name, $line->quantity, $line->net, $line->refunded,
            ], array_merge(...array_map(static fn(OrderFacts $facts): array => $facts->lines, $facts))));
            $this->insert($this->tables->coupons(), ['order_id', 'day', 'counted', 'currency', 'code', 'amount'], array_map(static fn(UsedCoupon $coupon): array => [
                $coupon->orderId, $coupon->day->format(self::DAY), (int) $coupon->counted, $coupon->currency, $coupon->code, $coupon->amount,
            ], array_merge(...array_map(static fn(OrderFacts $facts): array => $facts->coupons, $facts))));
            $this->insert($this->tables->refunds(), ['refund_id', 'order_id', 'day', 'currency', 'amount', 'tax'], array_map(static fn(GivenRefund $refund): array => [
                $refund->refundId, $refund->orderId, $refund->day->format(self::DAY), $refund->currency, $refund->amount, $refund->tax,
            ], array_merge(...array_map(static fn(OrderFacts $facts): array => $facts->refunds, $facts))));
            $this->insert($this->tables->taxes(), ['order_id', 'day', 'counted', 'currency', 'country', 'region', 'level', 'code', 'jurisdiction', 'percent', 'kind', 'taxable', 'amount', 'refunded', 'exempt'], array_map(static fn(CollectedTax $tax): array => [
                $tax->orderId, $tax->day->format(self::DAY), (int) $tax->counted, $tax->currency, $tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction, $tax->percent, $tax->kind, $tax->taxable, $tax->amount, $tax->refunded, (int) $tax->exempt,
            ], array_merge(...array_map(static fn(OrderFacts $facts): array => $facts->taxes, $facts))));
        });
    }

    /**
     * @param list<string>                          $columns
     * @param list<list<int|string|null>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        $row = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->tables->connection()->executeStatement(
                \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($chunk), $row))),
                array_merge(...$chunk),
            );
        }
    }
}
