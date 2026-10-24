<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Domain\CustomerRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;

/**
 * A customer's saved addresses, each under an id, and which of them checkout starts from for billing and for
 * shipping. The first saved is both until the customer says otherwise; shipping falls back to billing.
 */
final readonly class AddressBook
{
    public const int MAX_ADDRESSES = 20;
    public const string ID_PATTERN = '[a-f0-9]{8}';

    /**
     * @param array<string, AddressData> $addresses by id
     */
    public function __construct(
        public array $addresses = [],
        public ?string $billingId = null,
        public ?string $shippingId = null,
    ) {}

    public function billing(): ?AddressData
    {
        return $this->billingId === null ? null : $this->addresses[$this->billingId] ?? null;
    }

    public function shipping(): ?AddressData
    {
        return ($this->shippingId === null ? null : $this->addresses[$this->shippingId] ?? null) ?? $this->billing();
    }

    /**
     * Saves an address, as a new one or in place of the one of the id, and makes it the one to start from for
     * billing or shipping when asked (or when there is none yet).
     *
     * @return array{self, string} the book, and the address's id
     *
     * @throws CustomerRuleViolation for an id the book does not have, or a full book
     */
    public function with(?string $id, AddressData $address, bool $billing = false, bool $shipping = false): array
    {
        if ($id !== null && !isset($this->addresses[$id])) {
            throw new CustomerRuleViolation('There is no such saved address.', 'orillacart_unknown_address');
        }
        $id ??= $this->idOf($address) ?? $this->newId();
        $addresses = $this->addresses;
        $addresses[$id] = $address;
        if (\count($addresses) > self::MAX_ADDRESSES) {
            throw new CustomerRuleViolation('Save up to %d addresses: remove one you no longer need first.', 'orillacart_too_many_addresses', values: [self::MAX_ADDRESSES]);
        }

        return [new self(
            $addresses,
            $billing || $this->billing() === null ? $id : $this->billingId,
            $shipping || ($this->shippingId === null && $this->billing() === null) ? $id : $this->shippingId,
        ), $id];
    }

    /**
     * Forgets an address; another takes its place as the one to start from.
     *
     * @throws CustomerRuleViolation for an id the book does not have
     */
    public function without(string $id): self
    {
        if (!isset($this->addresses[$id])) {
            throw new CustomerRuleViolation('There is no such saved address.', 'orillacart_unknown_address');
        }
        $addresses = $this->addresses;
        unset($addresses[$id]);
        $first = array_key_first($addresses);
        $first = $first === null ? null : (string) $first;
        $billingId = $this->billingId === $id ? $first : $this->billingId;
        $shippingId = $this->shippingId === $id ? $billingId : $this->shippingId;

        return new self($addresses, $billingId, $shippingId);
    }

    /**
     * The addresses a customer ordered with, kept (once each) as the ones to start from next time. A full book
     * forgets the address it has had longest that is neither.
     */
    public function remembering(AddressData $billing, ?AddressData $shippingAddress): self
    {
        $book = $this;
        foreach (array_filter([$billing, $shippingAddress]) as $address) {
            if ($book->idOf($address) === null && \count($book->addresses) >= self::MAX_ADDRESSES) {
                foreach (array_keys($book->addresses) as $oldest) {
                    if ((string) $oldest !== $book->billingId && (string) $oldest !== $book->shippingId) {
                        $book = $book->without((string) $oldest);
                        break;
                    }
                }
            }
        }
        [$book] = $book->with(null, $billing, billing: true, shipping: $shippingAddress === null);
        if ($shippingAddress !== null) {
            [$book] = $book->with(null, $shippingAddress, shipping: true);
        }

        return $book;
    }

    private function idOf(AddressData $address): ?string
    {
        foreach ($this->addresses as $id => $saved) {
            if (get_object_vars($saved) === get_object_vars($address)) {
                return (string) $id;
            }
        }

        return null;
    }

    private function newId(): string
    {
        // Never digits only: PHP would make it an int key.
        do {
            $id = bin2hex(random_bytes(4));
        } while (ctype_digit($id) || isset($this->addresses[$id]));

        return $id;
    }
}
