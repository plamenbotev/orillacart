<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Tax\Domain\TaxExemptionRepository;

/** A customer's tax exemptions and the certificates they sent. */
final readonly class ExemptionPersonalData implements PersonalDataSource
{
    public function __construct(private TaxExemptionRepository $exemptions) {}

    public function personalData(string $email, ?int $customerId): array
    {
        $items = [];
        foreach ($customerId === null ? [] : $this->exemptions->ofCustomer($customerId) as $exemption) {
            $items[] = ['group' => 'orillacart-exemptions', 'label' => 'Tax exemptions', 'id' => 'orillacart-exemption-' . $exemption->id(), 'data' => array_values(array_filter([
                ['name' => 'Reason', 'value' => $exemption->reason()->value],
                ['name' => 'Certificate', 'value' => $exemption->certificate()],
                ['name' => 'Where', 'value' => $exemption->regions() === [] ? 'everywhere' : implode(', ', $exemption->regions())],
                $exemption->expiresOn() === null ? null : ['name' => 'Until', 'value' => $exemption->expiresOn()->format('Y-m-d')],
                ['name' => 'Status', 'value' => $exemption->status()->value],
                $exemption->file() === null ? null : ['name' => 'File sent', 'value' => $exemption->file()['name'] ?? ''],
            ]))];
        }

        return $items;
    }
}
