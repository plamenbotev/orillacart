<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Packages\Application\PackageService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What the extensions keep about a customer, in the personal data export (their records that refer to the
 * customer, their fields on the customer), and erased with the customer.
 */
final readonly class ExtensionPersonalData implements PersonalDataSource
{
    public function __construct(private ObjectStore $store, private PackageService $packages) {}

    public function personalData(string $email, ?int $customerId): array
    {
        if ($customerId === null) {
            return [];
        }
        $items = [];
        foreach ($this->packages->all() as $package) {
            foreach ($this->store->ofCustomer($package->id, $customerId) as $record) {
                $items[] = ['group' => 'orillacart-extension-' . $package->id, 'label' => $package->name, 'id' => \sprintf('orillacart-extension-%s-%s-%s', $package->id, \is_string($record['type'] ?? null) ? $record['type'] : 'record', is_numeric($record['id'] ?? null) ? (string) $record['id'] : ''), 'data' => self::rows($record)];
            }
            $fields = $this->store->fieldsOf($package->id, 'customer', $customerId);
            if ($fields !== []) {
                $items[] = ['group' => 'orillacart-extension-' . $package->id, 'label' => $package->name, 'id' => \sprintf('orillacart-extension-%s-customer-%d', $package->id, $customerId), 'data' => self::rows($fields)];
            }
        }

        return $items;
    }

    #[AsEventListener]
    public function erased(CustomerErased $event): void
    {
        $this->store->forgetCustomer($event->customerId);
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
