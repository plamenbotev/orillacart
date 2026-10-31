<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * The days, and the times of day, a carrier lets the customer choose for delivery: the weekdays it delivers,
 * how many days it needs, the time of day after which an order counts from the next day, how far ahead the
 * customer may choose, the dates it does not deliver, and its time slots.
 */
final readonly class DeliverySchedule
{
    public const int MAX_DAYS_AHEAD = 90;

    /**
     * @param list<int>          $weekdays  1 (Monday) to 7 (Sunday)
     * @param int                $leadDays  the days it needs: 1 delivers tomorrow at the earliest
     * @param string|null        $cutoff    "14:00": an order after it counts from the next day; null for none
     * @param int                $daysAhead how many days from the earliest the customer may choose among
     * @param list<string>       $closed    dates it does not deliver on, "2026-12-25"
     * @param list<DeliverySlot> $slots     the times of day; none for a whole day
     * @param bool               $required  the customer must choose; otherwise they may leave it to the carrier
     *
     * @throws ShippingRuleViolation
     */
    public function __construct(
        public array $weekdays,
        public int $leadDays = 1,
        public ?string $cutoff = null,
        public int $daysAhead = 14,
        public array $closed = [],
        public array $slots = [],
        public bool $required = false,
    ) {
        if ($weekdays === [] || array_diff($weekdays, range(1, 7)) !== [] || \count(array_unique($weekdays)) !== \count($weekdays)) {
            throw new ShippingRuleViolation('A delivery schedule delivers on at least one weekday, each once.', 'orillacart_invalid_delivery_schedule');
        }
        if ($leadDays < 0 || $leadDays > 60 || $daysAhead < 1 || $daysAhead > self::MAX_DAYS_AHEAD) {
            throw new ShippingRuleViolation('A delivery schedule needs 0 to 60 days, and offers 1 to %d days.', 'orillacart_invalid_delivery_schedule', values: [self::MAX_DAYS_AHEAD]);
        }
        if ($cutoff !== null && !DeliverySlot::isTime($cutoff)) {
            throw new ShippingRuleViolation('"%s" is not a time of day such as 14:00.', 'orillacart_invalid_delivery_schedule', values: [$cutoff]);
        }
        foreach ($closed as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new ShippingRuleViolation('"%s" is not a date such as 2026-12-25.', 'orillacart_invalid_delivery_schedule', values: [$date]);
            }
        }
        $ids = array_map(static fn(DeliverySlot $slot): string => $slot->id, $slots);
        if (\count(array_unique($ids)) !== \count($ids)) {
            throw new ShippingRuleViolation('A time slot is listed twice.', 'orillacart_invalid_delivery_schedule');
        }
    }

    /**
     * The dates the customer may choose, ordering now: from the earliest the carrier can deliver, for the days
     * ahead, on the weekdays it delivers and not on the dates it is closed.
     *
     * @param \DateTimeImmutable $now in the shop's time zone
     *
     * @return list<string> "2026-10-21", earliest first
     */
    public function days(\DateTimeImmutable $now): array
    {
        $first = $now->setTime(0, 0)->modify("+{$this->leadDays} days");
        if ($this->cutoff !== null && $now->format('H:i') >= $this->cutoff) {
            $first = $first->modify('+1 day');
        }
        $days = [];
        for ($day = 0; $day < $this->daysAhead; ++$day) {
            $date = $first->modify("+{$day} days");
            if (\in_array((int) $date->format('N'), $this->weekdays, true) && !\in_array($date->format('Y-m-d'), $this->closed, true)) {
                $days[] = $date->format('Y-m-d');
            }
        }

        return $days;
    }

    /**
     * When an order placed now arrives at the earliest: the first day the customer may choose. It holds until
     * today's cutoff, or else midnight; null when none of the days ahead is one it delivers on.
     *
     * @param \DateTimeImmutable $now in the shop's time zone
     */
    public function estimate(\DateTimeImmutable $now): ?DeliveryEstimate
    {
        $first = $this->days($now)[0] ?? null;
        if ($first === null) {
            return null;
        }
        if ($this->cutoff !== null && $now->format('H:i') < $this->cutoff) {
            [$hour, $minute] = array_map(intval(...), explode(':', $this->cutoff));

            return new DeliveryEstimate($first, $first, $now->setTime($hour, $minute), true);
        }

        return new DeliveryEstimate($first, $first, $now->setTime(0, 0)->modify('+1 day'));
    }

    public function slot(string $id): ?DeliverySlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->id === $id) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @return array{weekdays: list<int>, lead_days: int, cutoff: ?string, days_ahead: int, closed: list<string>, slots: list<array{id: string, from: string, to: string, limit: ?int}>, required: bool}
     */
    public function toArray(): array
    {
        return [
            'weekdays' => $this->weekdays,
            'lead_days' => $this->leadDays,
            'cutoff' => $this->cutoff,
            'days_ahead' => $this->daysAhead,
            'closed' => $this->closed,
            'slots' => array_map(static fn(DeliverySlot $slot): array => ['id' => $slot->id, 'from' => $slot->from, 'to' => $slot->to, 'limit' => $slot->limit], $this->slots),
            'required' => $this->required,
        ];
    }

    /**
     * @param array{weekdays: list<int>, lead_days: int, cutoff: ?string, days_ahead: int, closed: list<string>, slots: list<array{id: string, from: string, to: string, limit: ?int}>, required: bool} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            $row['weekdays'],
            $row['lead_days'],
            $row['cutoff'],
            $row['days_ahead'],
            $row['closed'],
            array_map(static fn(array $slot): DeliverySlot => new DeliverySlot($slot['id'], $slot['from'], $slot['to'], $slot['limit']), $row['slots']),
            $row['required'],
        );
    }
}
