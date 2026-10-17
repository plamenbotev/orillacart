<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Customers;

use OrillaCart\Customers\Domain\CustomerSettings;
use OrillaCart\Customers\Domain\CustomerSettingsRepository;

final class InMemoryCustomerSettingsRepository implements CustomerSettingsRepository
{
    public function __construct(public CustomerSettings $saved = new CustomerSettings()) {}

    public function load(): CustomerSettings
    {
        return $this->saved;
    }

    public function save(CustomerSettings $settings): void
    {
        $this->saved = $settings;
    }
}
