<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Customer;

/** The shop's customers, as add-ons read them. Since version 22; create() and search() since 27. */
interface Customers
{
    /** The customer, or null for none such. */
    public function find(int $customerId): ?CustomerSummary;

    /**
     * Makes the customer's account as the shop does by hand: no sign-up email, nobody told.
     *
     * @throws \InvalidArgumentException for an email that has an account already, an address the shop cannot take,
     *                                   or, on a network of sites, a customer who is not a test one (the shop
     *                                   invites those instead)
     */
    public function create(NewCustomer $customer): CustomerSummary;

    /**
     * The shop's customers by email (the whole address, any case) or by words in their email or name, newest first;
     * at most $limit (1 to 100).
     *
     * @return list<CustomerSummary>
     */
    public function search(?string $email, ?string $words = null, int $limit = 20): array;
}
