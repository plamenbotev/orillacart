<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\LifetimeValueView;

final readonly class CustomerView
{
    public function __construct(
        public int $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $registeredAt,
        /** The saved address checkout starts from for billing. */
        public ?AddressData $billing,
        /** The saved address checkout starts from for shipping. */
        public ?AddressData $shippingAddress,
        public int $orders,
        /** @var list<int> the customer groups they are in */
        public array $groupIds = [],
        /** @var list<SavedAddressView> */
        public array $addresses = [],
        public ?LifetimeValueView $lifetimeValue = null,
    ) {}

    /**
     * @param list<int> $groupIds
     */
    public static function fromRecord(CustomerRecord $record, int $orders, array $groupIds = [], ?LifetimeValueView $lifetimeValue = null): self
    {
        $book = $record->addresses;
        $shippingId = $book->shippingId !== null && isset($book->addresses[$book->shippingId]) ? $book->shippingId : $book->billingId;
        $saved = [];
        foreach ($book->addresses as $id => $address) {
            $saved[] = new SavedAddressView((string) $id, $address, (string) $id === $book->billingId, (string) $id === $shippingId);
        }

        return new self($record->id, $record->email, $record->firstName, $record->lastName, $record->registeredAt, $book->billing(), $book->shipping(), $orders, $groupIds, $saved, $lifetimeValue);
    }
}
