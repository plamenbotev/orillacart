<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Domain\AppliedTax;
use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\TaxGroup;
use OrillaCart\Tax\Domain\TaxLocation;
use OrillaCart\Tax\Domain\TaxRateDetails;
use OrillaCart\Tax\Domain\TaxRateNotFound;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use PHPUnit\Framework\TestCase;

final class TaxGroupTest extends TestCase
{
    public function testTaxesAtALocationAreTheWholeCountryRatesPlusTheStates(): void
    {
        $group = $this->usSalesTax();

        self::assertSame([['New York', '4'], ['Federal', '2'], ['MCTD', '0.375']], self::lines($group->taxesAt(new TaxLocation('US', 'NY'))->taxes));
        self::assertSame('6.375', (string) $group->taxesAt(new TaxLocation('US', 'NY'))->total());
        self::assertSame([['Federal', '2']], self::lines($group->taxesAt(new TaxLocation('US', 'TX'))->taxes));
        self::assertSame([['Federal', '2']], self::lines($group->taxesAt(new TaxLocation('US'))->taxes));
        self::assertTrue($group->taxesAt(new TaxLocation('CA', 'NY'))->isEmpty());
    }

    public function testRatesAreListedByPriorityThenAsAdded(): void
    {
        $group = new TaxGroup('Standard');
        $group->addRate(self::rate('BG', null, '20', 'VAT', 1));
        $group->addRate(self::rate('BG', null, '1.5', 'Eco', 0));
        $group->addRate(self::rate('BG', null, '2', 'Late', 1));

        self::assertSame(['Eco', 'VAT', 'Late'], array_map(static fn($rate): string => $rate->details()->name, $group->rates()));
    }

    public function testRatesAreChangedAndRemovedThroughTheirGroup(): void
    {
        $repository = new InMemoryTaxGroupRepository();
        $group = $this->usSalesTax();
        $repository->save($group);
        [$newYork] = $group->rates();

        $group->changeRate($newYork->id(), self::rate('US', 'NY', '4.5', 'New York', 1));
        self::assertSame('4.5', (string) $group->rate($newYork->id())->details()->percent);

        $group->removeRate($newYork->id());
        self::assertCount(2, $group->rates());

        $this->expectException(TaxRateNotFound::class);
        $group->rate($newYork->id());
    }

    public function testAGroupNeedsAName(): void
    {
        self::assertSame('Reduced', new TaxGroup('  Reduced ')->name());

        $this->expectException(RuleViolation::class);
        new TaxGroup('   ');
    }

    public function testRateDetailsAreValidated(): void
    {
        $this->expectException(RuleViolation::class);

        self::rate('us', null, '5', '', 0);
    }

    private function usSalesTax(): TaxGroup
    {
        $group = new TaxGroup('Standard');
        $group->addRate(self::rate('US', 'NY', '4', 'New York', 1));
        $group->addRate(self::rate('US', null, '2', 'Federal', 2));
        $group->addRate(self::rate('US', 'NY', '0.375', 'MCTD', 2));

        return $group;
    }

    private static function rate(string $country, ?string $state, string $percent, string $name, int $priority): TaxRateDetails
    {
        return new TaxRateDetails($country, $state, Percentage::of($percent), $name, $priority);
    }

    /**
     * @param list<AppliedTax> $taxes
     *
     * @return list<array{string, string}>
     */
    private static function lines(array $taxes): array
    {
        return array_map(static fn(AppliedTax $tax): array => [$tax->name, (string) $tax->percent], $taxes);
    }
}
