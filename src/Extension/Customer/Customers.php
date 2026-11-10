<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Customer;

/** The shop's customers, as add-ons read them. Since version 22. */
interface Customers
{
    /** The customer, or null for none such. */
    public function find(int $customerId): ?CustomerSummary;
}
