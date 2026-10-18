<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Invoicing;

use OrillaCart\Invoicing\Domain\InvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceSettingsRepository;

final class MemoryInvoiceSettings implements InvoiceSettingsRepository
{
    public function __construct(public InvoiceSettings $saved = new InvoiceSettings()) {}

    public function load(): InvoiceSettings
    {
        return $this->saved;
    }

    public function save(InvoiceSettings $settings): void
    {
        $this->saved = $settings;
    }
}
