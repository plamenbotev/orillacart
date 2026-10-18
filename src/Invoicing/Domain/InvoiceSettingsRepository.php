<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

interface InvoiceSettingsRepository
{
    public function load(): InvoiceSettings;

    public function save(InvoiceSettings $settings): void;
}
