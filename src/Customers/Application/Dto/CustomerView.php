<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;

final readonly class CustomerView
{
    public function __construct(
        public int $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $registeredAt,
        public ?AddressData $billing,
        public ?AddressData $shippingAddress,
        public int $orders,
    ) {}

    public static function fromRecord(CustomerRecord $record, int $orders): self
    {
        return new self($record->id, $record->email, $record->firstName, $record->lastName, $record->registeredAt, $record->billing, $record->shippingAddress, $orders);
    }
}
