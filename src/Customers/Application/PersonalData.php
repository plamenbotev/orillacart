<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What the shop holds of a person, for WordPress's privacy tools (Tools → Export and Erase Personal Data): their
 * orders and their account's addresses, given out or erased a page at a time. Erasing anonymises orders, which
 * the books keep (see Order::anonymise()), and forgets the rest.
 */
final readonly class PersonalData
{
    public const int PER_PAGE = 20;

    public function __construct(
        private OrderService $orders,
        private CustomerService $customers,
        private ?EventDispatcherInterface $events = null,
    ) {}

    /**
     * @return array{items: list<array{group: string, label: string, id: string, data: list<array{name: string, value: string}>}>, done: bool}
     */
    public function export(string $email, int $page): array
    {
        $items = [];
        if ($page === 1) {
            $id = $this->customers->customerIdOf($email);
            $customer = $id === null ? null : $this->customers->customer($id);
            foreach (['Billing address' => $customer?->billing, 'Shipping address' => $customer?->shippingAddress] as $label => $address) {
                if ($address instanceof AddressData) {
                    $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-' . strtok($label, ' '), 'data' => [['name' => $label, 'value' => self::address($address)]]];
                }
            }
        }
        $orders = $this->orders->ofPerson($email, $this->customers->customerIdOf($email), $page, self::PER_PAGE);
        foreach ($orders as $order) {
            $items[] = ['group' => 'orillacart-orders', 'label' => 'Orders', 'id' => "orillacart-order-{$order->number}", 'data' => self::orderData($order)];
        }

        return ['items' => $items, 'done' => \count($orders) < self::PER_PAGE];
    }

    /**
     * @return array{removed: bool, retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page): array
    {
        $customerId = $this->customers->customerIdOf($email);
        $removed = $page === 1 && $this->customers->erase($email);
        if ($page === 1) {
            $this->events?->dispatch(new PersonErased($email));
        }
        // Erased orders no longer match the email, so each page starts at the first that still does.
        $orders = $this->orders->ofPerson($email, $customerId, 1, self::PER_PAGE);
        foreach ($orders as $order) {
            $this->orders->anonymise($order->number, null);
        }

        return [
            'removed' => $removed || $orders !== [],
            'retained' => $orders !== [],
            'messages' => $orders === [] ? [] : [\sprintf('%d orders were kept for the books, with the customer\'s details erased.', \count($orders))],
            'done' => \count($orders) < self::PER_PAGE,
        ];
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
            ['name' => 'Billing address', 'value' => self::address($order->billing)],
            ['name' => 'Items', 'value' => implode(', ', array_map(static fn(OrderLineView $line): string => "{$line->quantity} × {$line->name}", $order->lines))],
        ];
        if ($order->shippingAddress !== null) {
            $data[] = ['name' => 'Shipping address', 'value' => self::address($order->shippingAddress)];
        }
        if ($order->vatNumber !== null) {
            $data[] = ['name' => 'VAT number', 'value' => $order->vatNumber];
        }
        if ($order->note !== '') {
            $data[] = ['name' => 'Note', 'value' => $order->note];
        }

        return $data;
    }

    private static function address(AddressData $address): string
    {
        $parts = [trim("{$address->firstName} {$address->lastName}"), $address->street, $address->city, $address->postcode, $address->state ?? '', $address->country, $address->phone];

        return implode(', ', array_filter(array_map(trim(...), $parts), static fn(string $part): bool => $part !== ''));
    }
}
