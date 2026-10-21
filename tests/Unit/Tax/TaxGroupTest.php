<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Shared\Domain\PostcodePattern;
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

    public function testARateForSomePostcodesAppliesOnlyThere(): void
    {
        $group = new TaxGroup('Standard');
        $group->addRate(self::rate('US', 'CA', '6', 'California'));
        $group->addRate(new TaxRateDetails('US', 'CA', Percentage::of('1.25'), 'Los Angeles', 1, [PostcodePattern::of('900*'), PostcodePattern::of('91000-91999')]));

        self::assertSame([['California', '6'], ['Los Angeles', '1.25']], self::lines($group->taxesAt(new TaxLocation('US', 'CA', postcode: '90012'))->taxes));
        self::assertSame([['California', '6'], ['Los Angeles', '1.25']], self::lines($group->taxesAt(new TaxLocation('US', 'CA', postcode: '91101-1234'))->taxes));
        self::assertSame([['California', '6']], self::lines($group->taxesAt(new TaxLocation('US', 'CA', postcode: '94103'))->taxes));
        self::assertSame([['California', '6']], self::lines($group->taxesAt(new TaxLocation('US', 'CA'))->taxes), 'not before the postcode is known');
    }

    public function testACompoundRateIsChargedOnTheTaxesBeforeIt(): void
    {
        $group = new TaxGroup('Standard');
        $group->addRate(self::rate('CA', 'QC', '5', 'GST'));
        $group->addRate(new TaxRateDetails('CA', 'QC', Percentage::of('9.975'), 'QST', 1, compound: true));
        $taxes = $group->taxesAt(new TaxLocation('CA', 'QC'));

        self::assertSame([false, true], array_map(static fn(AppliedTax $tax): bool => $tax->compound, $taxes->taxes));
        // 9.975 % of 105 % is 10.4738 % of the price.
        self::assertSame('15.4738', (string) $taxes->total());
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

    private static function rate(string $country, ?string $state, string $percent, string $name, int $priority = 0): TaxRateDetails
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
