<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Application\CustomerOrders;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Settings\Application\SettingsService;

/**
 * Orders and the customers they belong to: what the customers' side asks of their orders, and moving an order
 * to another account.
 */
final readonly class OrderCustomers implements CustomerOrders
{
    public function __construct(
        private OrderService $orders,
        private Accounts $accounts,
        private SettingsService $shop,
    ) {}

    /**
     * Moves an order to a customer's account, or makes it a guest's order.
     *
     * @throws CustomerNotFound for an account that does not exist or is not one of the shop's customers
     * @throws OrderNotFound
     */
    public function assign(int $orderId, ?int $customerId, ?int $userId): OrderView
    {
        // Only the shop's own customers take orders: not its staff, and on a network not another site's users.
        if ($customerId !== null && (!$this->accounts->isShopCustomer($customerId) || $this->accounts->find($customerId) === null)) {
            throw new CustomerNotFound($customerId);
        }

        return $this->orders->changeCustomer($orderId, $customerId, $userId);
    }

    public function counts(array $customerIds): array
    {
        return $this->orders->orderCounts($customerIds);
    }

    public function lifetimeValues(array $customerIds): array
    {
        $shop = $this->shop->current();

        return $this->orders->lifetimeValues($customerIds, $shop->currency, $shop->priceDecimals);
    }

    public function latestAddresses(int $customerId): ?array
    {
        $latest = $this->orders->orders(new OrderQuery(customerId: $customerId, perPage: 1))->orders[0] ?? null;
        if ($latest === null) {
            return null;
        }
        try {
            $order = $this->orders->order($latest->number);
        } catch (OrderNotFound) {
            return null;
        }

        return [$order->number, $order->billing, $order->shippingAddress];
    }

    public function guestOrdersOf(string $email): array
    {
        return $this->orders->guestOrdersOf($email);
    }

    public function giveTo(int $orderId, int $customerId): ?array
    {
        try {
            $order = $this->orders->order($orderId);
            $this->orders->giveTo($orderId, $customerId);
        } catch (OrderNotFound) {
            return null;
        }

        return [$order->billing, $order->shippingAddress];
    }

    public function exportPage(string $email, ?int $customerId, int $page, int $perPage): array
    {
        return array_map(
            static fn(OrderView $order): array => ['group' => 'orillacart-orders', 'label' => 'Orders', 'id' => "orillacart-order-{$order->number}", 'data' => self::orderData($order)],
            $this->orders->ofPerson($email, $customerId, $page, $perPage),
        );
    }

    public function erasePage(string $email, ?int $customerId, int $perPage): int
    {
        // Erased orders no longer match the email, so each page starts at the first that still does.
        $orders = $this->orders->ofPerson($email, $customerId, 1, $perPage);
        foreach ($orders as $order) {
            $this->orders->anonymise($order->number, null);
        }

        return \count($orders);
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private static function orderData(OrderView $order): array
    {
        $data = [
            ['name' => 'Order', 'value' => '#' . $order->number],
            ['name' => 'Placed', 'value' => $order->placedAt],
            ['name' => 'Status', 'value' => $order->status],
            ['name' => 'Total', 'value' => "{$order->total} {$order->currency}"],
            ['name' => 'Email', 'value' => $order->email],
            ['name' => 'Billing address', 'value' => $order->billing->oneLine()],
            ['name' => 'Items', 'value' => implode(', ', array_map(static fn(OrderLineView $line): string => "{$line->quantity} × {$line->name}", $order->lines))],
        ];
        if ($order->shippingAddress !== null) {
            $data[] = ['name' => 'Shipping address', 'value' => $order->shippingAddress->oneLine()];
        }
        if ($order->vatNumber !== null) {
            $data[] = ['name' => 'VAT number', 'value' => $order->vatNumber];
        }
        if ($order->note !== '') {
            $data[] = ['name' => 'Note', 'value' => $order->note];
        }
        // What they answered at checkout and for each item, which may name people or carry their files.
        foreach ($order->fields as $field) {
            $data[] = ['name' => $field->label, 'value' => $field->answer];
        }
        foreach ($order->lines as $line) {
            foreach ($line->personalisation as $answer) {
                if ($answer['answer'] !== '') {
                    $data[] = ['name' => "{$line->name}: {$answer['label']}", 'value' => $answer['answer']];
                }
            }
        }

        return $data;
    }
}
