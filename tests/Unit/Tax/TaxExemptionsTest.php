<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\SaveTaxExemption;
use OrillaCart\Tax\Application\TaxExemptions;
use OrillaCart\Tax\Domain\TaxLocation;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxExemptionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TaxExemptionsTest extends TestCase
{
    private TaxExemptions $exemptions;
    private MockClock $clock;
    private int $customer;

    protected function setUp(): void
    {
        $accounts = new InMemoryAccounts();
        $this->customer = $accounts->add('buyer@example.com');
        $this->clock = new MockClock('2026-10-23 10:00:00');
        $this->exemptions = new TaxExemptions(new InMemoryTaxExemptionRepository(), $accounts, $this->clock);
    }

    public function testAnExemptionCoversItsStatesUntilItsLastDay(): void
    {
        $texas = $this->exemptions->add($this->customer, new SaveTaxExemption('resale', ' TX-12345 ', ['us-tx'], '2026-12-31'));
        self::assertSame(['resale', 'TX-12345', ['US-TX'], '2026-12-31'], [$texas->reason, $texas->certificate, $texas->regions, $texas->expiresOn]);

        self::assertSame($texas->id, $this->exemptions->covering($this->customer, new TaxLocation('US', 'TX'))?->id);
        self::assertNull($this->exemptions->covering($this->customer, new TaxLocation('US', 'CA')), 'another state');
        self::assertNull($this->exemptions->covering(null, new TaxLocation('US', 'TX')), 'a guest');

        $this->clock->modify('2026-12-31 23:00:00');
        self::assertNotNull($this->exemptions->covering($this->customer, new TaxLocation('US', 'TX')), 'its last day');
        $this->clock->modify('2027-01-01 00:00:00');
        self::assertNull($this->exemptions->covering($this->customer, new TaxLocation('US', 'TX')), 'expired');
    }

    public function testOneWithoutRegionsHoldsEverywhereAndTheNewestCovers(): void
    {
        $this->exemptions->add($this->customer, new SaveTaxExemption('nonprofit', 'NP-1', ['US']));
        $everywhere = $this->exemptions->add($this->customer, new SaveTaxExemption('government', 'GOV-7'));

        self::assertSame($everywhere->id, $this->exemptions->covering($this->customer, new TaxLocation('DE'))?->id);
        self::assertSame(['GOV-7', 'NP-1'], array_column($this->exemptions->of($this->customer), 'certificate'));

        $this->exemptions->remove($this->customer, $everywhere->id);
        self::assertSame('NP-1', $this->exemptions->covering($this->customer, new TaxLocation('US', 'NY'))?->certificate);
        $this->exemptions->forgetCustomer($this->customer);
        self::assertSame([], $this->exemptions->of($this->customer));
    }

    public function testRefusesWhatCannotBeAnExemption(): void
    {
        foreach ([
            fn() => $this->exemptions->add(999, new SaveTaxExemption('resale', 'X')),
            fn() => $this->exemptions->add($this->customer, new SaveTaxExemption('resale', '   ')),
            fn() => $this->exemptions->add($this->customer, new SaveTaxExemption('resale', 'X', ['Texas'])),
            fn() => $this->exemptions->remove($this->customer, 42),
        ] as $index => $refused) {
            try {
                $refused();
                self::fail("case {$index} was taken");
            } catch (RuleViolation|CustomerNotFound) {
            }
        }
        self::assertSame([], $this->exemptions->of($this->customer));
    }
}
