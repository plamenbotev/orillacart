<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

/**
 * How a report's chart groups its days. Weeks start on Monday.
 */
enum Interval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** The first day of the group a day is in: what the chart labels it with. */
    public function start(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return match ($this) {
            self::Day => $day,
            self::Week => $day->modify('-' . ((int) $day->format('N') - 1) . ' days'),
            self::Month => $day->modify('first day of this month'),
        };
    }

    /** The first day of the next group. */
    public function next(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return $start->modify(match ($this) {
            self::Day => '+1 day',
            self::Week => '+1 week',
            self::Month => '+1 month',
        });
    }

    /**
     * The group a year before a group of this period, to compare with: the same day (none for a 29 February), the
     * same month, or the week of the same number, so a leap day does not shift every day after it.
     */
    public function yearBefore(\DateTimeImmutable $start): ?\DateTimeImmutable
    {
        return match ($this) {
            self::Day => $start->format('m-d') === '02-29' ? null : $start->modify('-1 year'),
            self::Month => $start->modify('-1 year'),
            self::Week => self::isoWeek((int) $start->format('o') - 1, (int) $start->format('W'), $start),
        };
    }

    private static function isoWeek(int $year, int $week, \DateTimeImmutable $like): ?\DateTimeImmutable
    {
        $monday = $like->setISODate($year, $week);

        return (int) $monday->format('W') === $week ? $monday : null;
    }

    /**
     * The interval that suits a period: days for two months or less, weeks up to a year, months beyond.
     */
    public static function suiting(Period $period): self
    {
        return match (true) {
            $period->days() <= 62 => self::Day,
            $period->days() <= 366 => self::Week,
            default => self::Month,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
