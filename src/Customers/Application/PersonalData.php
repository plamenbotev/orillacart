<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What the shop holds of a person, for WordPress's privacy tools (Tools → Export and Erase Personal Data): their
 * orders, their account's addresses, the shop's notes about them and their newsletter sign-up, given out or
 * erased a page at a time. Erasing anonymises orders, which the books keep (see Order::anonymise()), and forgets
 * the rest.
 */
final readonly class PersonalData
{
    public const int PER_PAGE = 20;

    public function __construct(
        private OrderService $orders,
        private CustomerService $customers,
        private CustomerNotes $notes,
        private Newsletter $newsletter,
        private Accounts $accounts,
        private ShopCatalog $catalog,
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
            foreach ($customer->addresses ?? [] as $saved) {
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-address-' . $saved->id, 'data' => [['name' => 'Saved address', 'value' => self::address($saved->address)]]];
            }
            $subscription = $this->newsletter->subscription($email);
            if ($subscription !== null) {
                $items[] = ['group' => 'orillacart-newsletter', 'label' => 'Newsletter', 'id' => 'orillacart-newsletter', 'data' => array_values(array_filter([
                    ['name' => 'Signed up', 'value' => $subscription->subscribedAt],
                    ['name' => 'Agreed to', 'value' => $subscription->consent],
                    $subscription->unsubscribedAt === null ? null : ['name' => 'Unsubscribed', 'value' => $subscription->unsubscribedAt],
                ]))];
            }
            $wishlist = $id === null ? [] : $this->accounts->wishlist($id);
            if ($wishlist !== []) {
                $names = array_map(static fn(ProductOffer $offer): string => $offer->name, $this->catalog->offers($wishlist, $id));
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-wishlist', 'data' => [['name' => 'Wishlist', 'value' => implode(', ', array_map(static fn(int $productId): string => $names[$productId] ?? "#{$productId}", $wishlist))]]];
            }
            foreach ($id === null ? [] : $this->notes->of($id) as $note) {
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-note-' . $note->id, 'data' => [['name' => 'The shop\'s note', 'value' => $note->text]]];
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
        $removed = false;
        if ($page === 1) {
            $account = $this->customers->erase($email);
            $removed = $this->newsletter->erase($email) || $account;
        }
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
