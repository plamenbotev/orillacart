<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\DeliverySchedule;
use OrillaCart\Shipping\Domain\DeliverySlot;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The days, and times of day, a carrier lets the customer choose for delivery, as the Shipping screen saves them.
 */
final readonly class SaveDeliverySchedule
{
    /**
     * @param list<int>              $weekdays 1 (Monday) to 7 (Sunday)
     * @param list<string>           $closed   dates it does not deliver on, "2026-12-25"
     * @param list<SaveDeliverySlot> $slots
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 7)]
        #[Assert\All([new Assert\Range(min: 1, max: 7)])]
        public array $weekdays,
        #[Assert\Range(min: 0, max: 60)]
        public int $leadDays = 1,
        #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use a time of day such as 14:00.')]
        public ?string $cutoff = null,
        #[Assert\Range(min: 1, max: DeliverySchedule::MAX_DAYS_AHEAD)]
        public int $daysAhead = 14,
        #[Assert\Count(max: 366)]
        #[Assert\All([new Assert\Date()])]
        public array $closed = [],
        #[Assert\Valid]
        #[Assert\Count(max: 24)]
        public array $slots = [],
        public bool $required = false,
    ) {}

    public function toSchedule(): DeliverySchedule
    {
        return new DeliverySchedule(
            array_values(array_unique($this->weekdays)),
            $this->leadDays,
            $this->cutoff,
            $this->daysAhead,
            array_values(array_unique($this->closed)),
            array_map(static fn(SaveDeliverySlot $slot): DeliverySlot => new DeliverySlot($slot->id, $slot->from, $slot->to, $slot->limit), $this->slots),
            $this->required,
        );
    }

    public static function fromSchedule(DeliverySchedule $schedule): self
    {
        return new self(
            $schedule->weekdays,
            $schedule->leadDays,
            $schedule->cutoff,
            $schedule->daysAhead,
            $schedule->closed,
            array_map(static fn(DeliverySlot $slot): SaveDeliverySlot => new SaveDeliverySlot($slot->id, $slot->from, $slot->to, $slot->limit), $schedule->slots),
            $schedule->required,
        );
    }
}
