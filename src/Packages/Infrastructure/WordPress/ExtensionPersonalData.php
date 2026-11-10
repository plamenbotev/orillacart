<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Packages\Application\PackageService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What the extensions keep about a person, in the personal data export (their records that refer to the customer
 * or to one of the person's orders, their fields on the customer and on those orders, a guest's too), and erased
 * with the customer, and with each order the person is erased from.
 */
final readonly class ExtensionPersonalData implements PersonalDataSource
{
    /** Orders read at a time, */
    private const int PER_PAGE = 100;
    /** and at most so many of them. */
    private const int MOST_PAGES = 50;

    public function __construct(
        private ObjectStore $store,
        private PackageService $packages,
        private OrderService $orders,
    ) {}

    public function personalData(string $email, ?int $customerId): array
    {
        $orderIds = $this->orderIds($email, $customerId);
        if ($customerId === null && $orderIds === []) {
            return [];
        }
        $items = [];
        foreach ($this->packages->all() as $package) {
            $records = [];
            foreach ([...($customerId === null ? [] : $this->store->ofCustomer($package->id, $customerId)), ...$this->store->ofOrders($package->id, $orderIds)] as $record) {
                $records[\is_string($record['type'] ?? null) ? $record['type'] : 'record'][is_numeric($record['id'] ?? null) ? (string) $record['id'] : ''] = $record;
            }
            foreach ($records as $type => $ofType) {
                foreach ($ofType as $id => $record) {
                    $items[] = ['group' => 'orillacart-extension-' . $package->id, 'label' => $package->name, 'id' => \sprintf('orillacart-extension-%s-%s-%s', $package->id, $type, $id), 'data' => self::rows($record)];
                }
            }
            $fields = $customerId === null ? [] : $this->store->fieldsOf($package->id, 'customer', $customerId);
            if ($fields !== []) {
                $items[] = ['group' => 'orillacart-extension-' . $package->id, 'label' => $package->name, 'id' => \sprintf('orillacart-extension-%s-customer-%d', $package->id, $customerId), 'data' => self::rows($fields)];
            }
            foreach ($orderIds === [] ? [] : $this->store->fieldsOfMany($package->id, 'order', $orderIds) as $orderId => $orderFields) {
                $items[] = ['group' => 'orillacart-extension-' . $package->id, 'label' => $package->name, 'id' => \sprintf('orillacart-extension-%s-order-%d', $package->id, $orderId), 'data' => self::rows(['order' => $orderId] + $orderFields)];
            }
        }

        return $items;
    }

    #[AsEventListener]
    public function erased(CustomerErased $event): void
    {
        $this->store->forgetCustomer($event->customerId);
    }

    /** The customer was erased from an order: what the extensions keep on it and of it goes too. */
    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        $this->store->forgetOrder($event->orderId);
    }

    /**
     * The person's orders, by their email or their account.
     *
     * @return list<int>
     */
    private function orderIds(string $email, ?int $customerId): array
    {
        if (trim($email) === '' && $customerId === null) {
            return [];
        }
        $ids = [];
        for ($page = 1; $page <= self::MOST_PAGES; ++$page) {
            $orders = $this->orders->ofPerson($email, $customerId, $page, self::PER_PAGE);
            foreach ($orders as $order) {
                $ids[] = $order->number;
            }
            if (\count($orders) < self::PER_PAGE) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<array{name: string, value: string}>
     */
    private static function rows(array $record): array
    {
        $rows = [];
        foreach ($record as $name => $value) {
            $rows[] = ['name' => (string) $name, 'value' => \is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE)];
        }

        return $rows;
    }
}
