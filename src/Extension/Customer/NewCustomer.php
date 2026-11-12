<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Customer;

use OrillaCart\Extension\Order\OrderAddress;

/**
 * A customer an add-on makes (API 27), as the shop makes one by hand: an account nobody is told of, with a password
 * nobody knows until the customer sets theirs through the lost-password link. The addresses, when given, go in the
 * account's address book as the ones checkout starts from; without them the account keeps none.
 */
final readonly class NewCustomer
{
    /**
     * @param bool $test made to try the shop out (design 62): never emailed, its orders are test orders, removed with
     *                   the shop's test data
     */
    public function __construct(
        public string $email,
        public string $firstName = '',
        public string $lastName = '',
        public ?OrderAddress $billing = null,
        public ?OrderAddress $shippingAddress = null,
        public bool $test = false,
    ) {}
}
