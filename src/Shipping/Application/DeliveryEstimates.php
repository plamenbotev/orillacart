<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Shipping\Application\Dto\DeliveryEstimateView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\DeliveryEstimate;
use Psr\Clock\ClockInterface;

/**
 * By when an order would arrive, for the product page: the soonest of the carriers that deliver the parcel to
 * the shopper, each by its delivery schedule, else by the working days it takes. Pages are kept in caches, so
 * the estimates follow one another for the days ahead, each holding until a cutoff or midnight; the page's
 * script shows the one that holds when it is read.
 */
final readonly class DeliveryEstimates
{
    /** How far ahead the estimates go: a page cached for a week still tells the truth. */
    private const int DAYS = 8;
    private const int MOST = 24;

    public function __construct(
        private ShippingQuotes $quotes,
        private ShopCalendar $calendar,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<DeliveryEstimateView> the one that holds now first; none when no carrier says when it delivers
     */
    public function forParcel(ShippingQuery $query): array
    {
        $carriers = array_values(array_filter($this->quotes->delivering($query), static fn(Carrier $carrier): bool => $carrier->deliverySchedule() !== null || $carrier->deliveryWindow() !== null));
        if ($carriers === []) {
            return [];
        }
        $at = $this->clock->now()->setTimezone($this->calendar->timezone());
        $end = $at->modify('+' . self::DAYS . ' days');
        $views = [];
        while ($at < $end && \count($views) < self::MOST) {
            [$soonest, $until] = self::soonest($carriers, $at);
            if ($soonest === null || $until === null) {
                break;
            }
            $orderBy = $soonest->cutoff && $soonest->until->getTimestamp() === $until->getTimestamp() ? $until : null;
            $last = array_last($views);
            if ($last !== null && $last->from === $soonest->from && $last->to === $soonest->to) {
                // The same days still: one estimate, until the later moment.
                array_pop($views);
            }
            $views[] = new DeliveryEstimateView($soonest->from, $soonest->to, $until, $orderBy);
            $at = $until;
        }

        return $views;
    }

    /**
     * The soonest of the carriers' estimates at a moment, and when the first of them stops holding.
     *
     * @param list<Carrier> $carriers
     *
     * @return array{?DeliveryEstimate, ?\DateTimeImmutable}
     */
    private static function soonest(array $carriers, \DateTimeImmutable $at): array
    {
        $soonest = null;
        $until = null;
        foreach ($carriers as $carrier) {
            $estimate = $carrier->deliveryEstimate($at);
            if ($estimate === null) {
                continue;
            }
            if ($until === null || $estimate->until < $until) {
                $until = $estimate->until;
            }
            if ($soonest === null || $estimate->sooner($soonest)) {
                $soonest = $estimate;
            }
        }

        return [$soonest, $until];
    }
}
