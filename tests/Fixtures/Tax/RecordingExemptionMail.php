<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Application\ExemptionMail;

final class RecordingExemptionMail implements ExemptionMail
{
    /** @var list<array{kind: string, to: string, certificate: string, status: string}> */
    public array $sent = [];

    public function received(TaxExemptionView $exemption, string $customerName, string $customerEmail): void
    {
        $this->sent[] = ['kind' => 'received', 'to' => 'shop', 'certificate' => $exemption->certificate, 'status' => $exemption->status];
    }

    public function reviewed(TaxExemptionView $exemption, string $email, string $firstName): void
    {
        $this->sent[] = ['kind' => 'reviewed', 'to' => $email, 'certificate' => $exemption->certificate, 'status' => $exemption->status];
    }

    public function expiring(TaxExemptionView $exemption, string $email, string $firstName): void
    {
        $this->sent[] = ['kind' => 'expiring', 'to' => $email, 'certificate' => $exemption->certificate, 'status' => $exemption->status];
    }
}
