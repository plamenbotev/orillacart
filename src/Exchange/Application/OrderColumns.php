<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderLine;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The columns of the orders export: the shop's own, then the add-ons' (OrderColumn). An add-on's column whose id
 * is one of the shop's is logged and left out.
 */
final readonly class OrderColumns
{
    public function __construct(
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<Column<OrderSummary>>
     */
    public function own(): array
    {
        $date = static fn(?\DateTimeImmutable $at): string => $at?->format('Y-m-d H:i:s') ?? '';
        $name = static fn(?OrderAddress $address): string => $address === null ? '' : trim("{$address->firstName} {$address->lastName}");
        $columns = [
            new Column('number', 'Order', static fn(OrderSummary $order): string => (string) $order->number),
            new Column('status', 'Status', static fn(OrderSummary $order): string => $order->status),
            new Column('placed_at', 'Placed (UTC)', static fn(OrderSummary $order): string => $date($order->placedAt)),
            new Column('paid_at', 'Paid (UTC)', static fn(OrderSummary $order): string => $date($order->paidAt)),
            new Column('email', 'Email', static fn(OrderSummary $order): string => $order->email),
            new Column('customer_id', 'Customer', static fn(OrderSummary $order): string => $order->customerId === null ? '' : (string) $order->customerId),
            new Column('billing_name', 'Billing name', static fn(OrderSummary $order): string => $name($order->billing)),
            new Column('billing_street', 'Billing street', static fn(OrderSummary $order): string => $order->billing->street),
            new Column('billing_city', 'Billing city', static fn(OrderSummary $order): string => $order->billing->city),
            new Column('billing_postcode', 'Billing postcode', static fn(OrderSummary $order): string => $order->billing->postcode),
            new Column('billing_country', 'Billing country', static fn(OrderSummary $order): string => $order->billing->country),
            new Column('billing_phone', 'Phone', static fn(OrderSummary $order): string => $order->billing->phone),
            new Column('shipping_name', 'Shipping name', static fn(OrderSummary $order): string => $name($order->shippingAddress)),
            new Column('shipping_address', 'Shipping address', static fn(OrderSummary $order): string => $order->shippingAddress === null ? '' : implode(', ', array_filter([$order->shippingAddress->street, $order->shippingAddress->postcode . ' ' . $order->shippingAddress->city, $order->shippingAddress->country], static fn(string $part): bool => trim($part) !== ''))),
            new Column('items', 'Items', static fn(OrderSummary $order): string => implode('; ', array_map(static fn(OrderLine $line): string => "{$line->quantity} × {$line->name}", $order->lines))),
            new Column('item_count', 'Item count', static fn(OrderSummary $order): string => (string) array_sum(array_map(static fn(OrderLine $line): int => $line->quantity, $order->lines))),
            new Column('subtotal', 'Subtotal', static fn(OrderSummary $order): string => $order->subtotal),
            new Column('discount', 'Discount', static fn(OrderSummary $order): string => $order->discount),
            new Column('shipping_total', 'Shipping', static fn(OrderSummary $order): string => $order->shippingTotal),
            new Column('tax', 'Tax', static fn(OrderSummary $order): string => $order->tax),
            new Column('total', 'Total', static fn(OrderSummary $order): string => $order->total),
            new Column('refunded', 'Refunded', static fn(OrderSummary $order): string => $order->refunded),
            new Column('currency', 'Currency', static fn(OrderSummary $order): string => $order->currency),
            new Column('payment', 'Payment', static fn(OrderSummary $order): string => $order->payment ?? ''),
            new Column('transaction_id', 'Transaction', static fn(OrderSummary $order): string => $order->transactionId ?? ''),
            new Column('shipping', 'Shipping method', static fn(OrderSummary $order): string => $order->shipping ?? ''),
            new Column('note', 'Customer note', static fn(OrderSummary $order): string => $order->note),
        ];

        return $columns;
    }

    /**
     * The shop's columns and the add-ons', by id.
     *
     * @return array<string, Column<OrderSummary>|OrderColumn>
     */
    public function all(): array
    {
        $columns = [];
        foreach ($this->own() as $column) {
            $columns[$column->id] = $column;
        }
        foreach ($this->extensions->orderColumns() as $column) {
            if (isset($columns[$column->id()])) {
                $this->logger->error('An add-on\'s order column uses the id "{id}", which is the shop\'s own. It is left out.', ['id' => $column->id()]);
                continue;
            }
            $columns[$column->id()] = $column;
        }

        return $columns;
    }
}
