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

    public function testAYearBeforeKeepsEachDayWeekAndMonthInStepOverALeapDay(): void
    {
        $leap = Period::of('2028-02-29', '2028-03-01', new \DateTimeZone('UTC'))->yearBefore();
        self::assertSame(['2027-02-28', '2027-03-01'], [$leap->from->format('Y-m-d'), $leap->to->format('Y-m-d')], 'not 1 March twice');

        $day = static fn(string $date): \DateTimeImmutable => new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        self::assertNull(Interval::Day->yearBefore($day('2028-02-29')), 'no 29 February to compare with');
        self::assertSame('2027-03-01', Interval::Day->yearBefore($day('2028-03-01'))?->format('Y-m-d'), 'the days after it stay in step');
        self::assertSame('2027-02-01', Interval::Month->yearBefore($day('2028-02-01'))?->format('Y-m-d'));
        self::assertSame('2029-03-05', Interval::Week->yearBefore($day('2030-03-04'))?->format('Y-m-d'), 'week 10 of the year before, a Monday');
        self::assertNull(Interval::Week->yearBefore($day('2026-12-28')), 'week 53 of 2026; 2025 had 52');
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
