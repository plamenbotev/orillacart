<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Shipping\Application\Dto\DeliveryChoiceView;
use OrillaCart\Shipping\Application\Dto\DeliveryDayView;
use OrillaCart\Shipping\Application\Dto\DeliveryPick;
use OrillaCart\Shipping\Application\Dto\DeliverySlotView;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\DeliverySchedule;
use OrillaCart\Shipping\Domain\DeliverySlot;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use Psr\Clock\ClockInterface;

/**
 * The days, and times of day, a way to ship lets the customer choose for delivery, in the shop's calendar: from
 * its carrier's schedule, leaving out the slots that took their orders for the day.
 */
final readonly class DeliveryDays
{
    public function __construct(
        private CarrierRepository $carriers,
        private OrderService $orders,
        private ShopCalendar $calendar,
        private ClockInterface $clock,
    ) {}

    /** Null for a way to ship whose carrier lets the customer choose no day. */
    public function forOption(?string $option): ?DeliveryChoiceView
    {
        [$carrier, $schedule] = $this->schedule($option);
        if ($carrier === null || $schedule === null) {
            return null;
        }
        $days = $schedule->days($this->clock->now()->setTimezone($this->calendar->timezone()));
        if ($days === []) {
            return new DeliveryChoiceView($schedule->required, []);
        }
        $booked = $this->orders->deliveriesBooked("{$carrier->id()}:", new \DateTimeImmutable($days[0]), new \DateTimeImmutable(end($days)));
        $views = [];
        foreach ($days as $day) {
            $slots = array_map(static fn(DeliverySlot $slot): DeliverySlotView => new DeliverySlotView($slot->id, $slot->label(), $slot->limit !== null && ($booked["{$day}|{$slot->id}"] ?? 0) >= $slot->limit), $schedule->slots);
            if ($slots === [] || array_filter($slots, static fn(DeliverySlotView $slot): bool => !$slot->full) !== []) {
                $views[] = new DeliveryDayView($day, $slots);
            }
        }

        return new DeliveryChoiceView($schedule->required, $views);
    }

    /**
     * The day and time of day chosen, checked against what the way to ship offers now.
     *
     * @return DeliveryPick|null null when it offers no day, or the customer left an optional one out
     *
     * @throws ShippingRuleViolation for a day or a slot it does not offer, a full slot, or none when one is needed
     */
    public function pick(?string $option, ?string $date, ?string $slot): ?DeliveryPick
    {
        [$carrier] = $this->schedule($option);
        $choice = $this->forOption($option);
        if ($carrier === null || $choice === null) {
            return null;
        }
        if ($date === null || $date === '') {
            if ($choice->required) {
                throw new ShippingRuleViolation('Choose the day of delivery.', 'orillacart_delivery_day_required');
            }

            return null;
        }
        foreach ($choice->days as $day) {
            if ($day->date !== $date) {
                continue;
            }
            if ($day->slots === []) {
                return new DeliveryPick($date, null, null);
            }
            foreach ($day->slots as $offered) {
                if ($offered->id === $slot) {
                    if ($offered->full) {
                        throw new ShippingRuleViolation('%s on that day is fully booked. Choose another time.', 'orillacart_delivery_slot_full', values: [$offered->label]);
                    }

                    return new DeliveryPick($date, $offered->label, "{$carrier->id()}:{$offered->id}");
                }
            }

            throw new ShippingRuleViolation('Choose the time of day for delivery.', 'orillacart_delivery_slot_required');
        }

        throw new ShippingRuleViolation('That day is not one delivery can be on. Choose another.', 'orillacart_delivery_day_unavailable');
    }

    /**
     * @return array{?Carrier, ?DeliverySchedule}
     */
    private function schedule(?string $option): array
    {
        $carrier = match (true) {
            $option === null => null,
            preg_match('/^(?:carrier|pickup):(\d+):/', $option, $parts) === 1 => $this->carriers->find((int) $parts[1]),
            preg_match('/^rate:(\d+)$/', $option, $parts) === 1 => $this->ofRate((int) $parts[1]),
            default => null,
        };

        return [$carrier, $carrier?->deliverySchedule()];
    }

    private function ofRate(int $rateId): ?Carrier
    {
        foreach ($this->carriers->all() as $carrier) {
            foreach ($carrier->rates() as $rate) {
                if ($rate->id() === $rateId) {
                    return $carrier;
                }
            }
        }

        return null;
    }
}
