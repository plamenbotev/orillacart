<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Domain;

/**
 * Days of the shop's calendar a report covers, the first and the last included.
 */
final readonly class Period
{
    /** Five years of days at most. */
    public const int MAX_DAYS = 1830;

    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    /**
     * @throws InvalidPeriod for a period that ends before it starts, or is too long
     */
    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        $this->from = $from->setTime(0, 0);
        $this->to = $to->setTime(0, 0);
        if ($this->to < $this->from) {
            throw new InvalidPeriod('A report period ends on or after its first day.');
        }
        if ($this->days() > self::MAX_DAYS) {
            throw new InvalidPeriod('A report covers %d days at most.', values: [self::MAX_DAYS]);
        }
    }

    /**
     * @throws InvalidPeriod for dates that are not Y-m-d, or a period that is not one
     */
    public static function of(string $from, string $to, \DateTimeZone $zone): self
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            throw new InvalidPeriod('Report dates are days such as 2026-10-18.');
        }

        return new self($start, $end);
    }

    /**
     * The given number of days, the last one included.
     *
     * @throws InvalidPeriod
     */
    public static function endingOn(string $to, int $days, \DateTimeZone $zone): self
    {
        $end = self::of($to, $to, $zone)->to;

        return new self($end->modify('-' . ($days - 1) . ' days'), $end);
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** As many days, just before it. */
    public function previous(): self
    {
        return new self($this->from->modify('-' . $this->days() . ' days'), $this->from->modify('-1 day'));
    }

    /** The same days a year before; a 29 February is the 28th then. */
    public function yearBefore(): self
    {
        return new self(self::yearEarlier($this->from), self::yearEarlier($this->to));
    }

    /** The same day a year earlier, the 28th for a 29 February (PHP alone makes it 1 March). */
    public static function yearEarlier(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->format('m-d') === '02-29' ? $day->modify('-1 year -1 day') : $day->modify('-1 year');
    }

    /**
     * The first day of each group of days in the period, in order.
     *
     * @return list<string> Y-m-d
     */
    public function buckets(Interval $interval): array
    {
        $buckets = [];
        for ($start = $interval->start($this->from); $start <= $this->to; $start = $interval->next($start)) {
            $buckets[] = $start->format('Y-m-d');
        }

        return $buckets;
    }
}
