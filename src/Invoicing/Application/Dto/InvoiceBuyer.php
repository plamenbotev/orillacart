<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderView;

/**
 * Whom an invoice or credit note was issued to, as the order said then: kept with the document, as the order's
 * contact may change and an erasure blanks it, and an issued document never changes.
 */
final readonly class InvoiceBuyer
{
    public function __construct(
        public AddressData $billing,
        public ?AddressData $shippingAddress,
        public string $email,
        public ?string $vatNumber,
        public ?string $buyerReference,
    ) {}

    public static function of(OrderView $order): self
    {
        return new self($order->billing, $order->shippingAddress, $order->email, $order->vatNumber, $order->buyerReference);
    }

    /**
     * As the invoice keeps it; null for one that is not.
     *
     * @param array<mixed>|null $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        $billing = self::address($stored['billing'] ?? null);
        if ($stored === null || $billing === null || !\is_string($stored['email'] ?? null)) {
            return null;
        }
        $text = static fn(string $key): ?string => \is_string($stored[$key] ?? null) ? $stored[$key] : null;

        return new self($billing, self::address($stored['shipping'] ?? null), $stored['email'], $text('vat_number'), $text('buyer_reference'));
    }

    /** @return array{billing: array<string, string|null>, shipping: array<string, string|null>|null, email: string, vat_number: string|null, buyer_reference: string|null} */
    public function stored(): array
    {
        return ['billing' => self::fields($this->billing), 'shipping' => $this->shippingAddress === null ? null : self::fields($this->shippingAddress), 'email' => $this->email, 'vat_number' => $this->vatNumber, 'buyer_reference' => $this->buyerReference];
    }

    /** @return array<string, string|null> */
    private static function fields(AddressData $address): array
    {
        return ['first_name' => $address->firstName, 'last_name' => $address->lastName, 'street' => $address->street, 'city' => $address->city, 'postcode' => $address->postcode, 'country' => $address->country, 'phone' => $address->phone, 'state' => $address->state];
    }

    private static function address(mixed $stored): ?AddressData
    {
        if (!\is_array($stored)) {
            return null;
        }
        $text = static fn(string $key): string => \is_string($stored[$key] ?? null) ? $stored[$key] : '';

        return new AddressData($text('first_name'), $text('last_name'), $text('street'), $text('city'), $text('postcode'), $text('country'), $text('phone'), \is_string($stored['state'] ?? null) ? $stored['state'] : null);
    }
}
