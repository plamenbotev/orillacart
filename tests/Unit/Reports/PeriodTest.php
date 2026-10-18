<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Reports;

use OrillaCart\Reports\Domain\Interval;
use OrillaCart\Reports\Domain\InvalidPeriod;
use OrillaCart\Reports\Domain\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testAPeriodComparesWithTheDaysBeforeOrAYearBefore(): void
    {
        $period = Period::of('2030-03-01', '2030-03-31', new \DateTimeZone('UTC'));

        self::assertSame(31, $period->days());
        self::assertSame(['2030-01-29', '2030-02-28'], [$period->previous()->from->format('Y-m-d'), $period->previous()->to->format('Y-m-d')]);
        self::assertSame(['2029-03-01', '2029-03-31'], [$period->yearBefore()->from->format('Y-m-d'), $period->yearBefore()->to->format('Y-m-d')]);
        self::assertSame(['2030-02-28', '2030-03-29'], [Period::endingOn('2030-03-29', 30, new \DateTimeZone('UTC'))->from->format('Y-m-d'), Period::endingOn('2030-03-29', 30, new \DateTimeZone('UTC'))->to->format('Y-m-d')]);
    }

    public function testItsDaysAreGroupedByDayWeekOrMonth(): void
    {
        $period = Period::of('2030-03-01', '2030-03-15', new \DateTimeZone('UTC'));

        self::assertCount(15, $period->buckets(Interval::Day));
        self::assertSame(['2030-02-25', '2030-03-04', '2030-03-11'], $period->buckets(Interval::Week), 'weeks start on Monday');
        self::assertSame(['2030-03-01'], $period->buckets(Interval::Month));
        self::assertSame([Interval::Day, Interval::Week, Interval::Month], [
            Interval::suiting($period),
            Interval::suiting(Period::of('2030-01-01', '2030-06-30', new \DateTimeZone('UTC'))),
            Interval::suiting(Period::of('2028-01-01', '2030-06-30', new \DateTimeZone('UTC'))),
        ]);
    }

    public function testAPeriodEndsAfterItStartsAndIsMadeOfDays(): void
    {
        foreach ([['2030-03-02', '2030-03-01'], ['2030-02-30', '2030-03-01'], ['2020-01-01', '2030-01-01']] as [$from, $to]) {
            try {
                Period::of($from, $to, new \DateTimeZone('UTC'));
                self::fail("{$from} to {$to}");
            } catch (InvalidPeriod) {
                self::addToAssertionCount(1);
            }
        }
    }
}
