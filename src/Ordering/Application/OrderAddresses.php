<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Geography\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\Address;
use OrillaCart\Ordering\Domain\OrderRuleViolation;

/**
 * An order's address and the address as typed, shown or kept by an account.
 */
final class OrderAddresses
{
    public static function data(Address $address): AddressData
    {
        return new AddressData($address->firstName(), $address->lastName(), $address->street(), $address->city(), $address->postcode(), $address->country(), $address->phone(), $address->state());
    }

    /**
     * @throws OrderRuleViolation for a missing name, street or city, or a malformed country or state code
     */
    public static function address(AddressData $data): Address
    {
        return Address::of($data->firstName, $data->lastName, $data->street, $data->city, $data->postcode, $data->country, $data->state, $data->phone);
    }
}
