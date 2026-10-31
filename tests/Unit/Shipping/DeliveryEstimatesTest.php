<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Shipping\Application\DeliveryEstimates;
use OrillaCart\Shipping\Application\Dto\DeliveryEstimateView;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveDeliverySchedule;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Domain\DeliverySchedule;
use OrillaCart\Shipping\Domain\DeliveryWindow;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Invoicing\SofiaCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The product page tells by when an order placed now arrives: from a carrier's delivery schedule, else from the
 * working days it takes, the soonest of the carriers that deliver there.
 */
final class DeliveryEstimatesTest extends TestCase
{
    private TestShop $shop;
    private DeliveryEstimates $estimates;

    protected function setUp(): void
    {
        // A Thursday, 10:40 in Sofia.
        $this->shop = new TestShop('2026-11-05 08:40:00 UTC');
        $this->estimates = new DeliveryEstimates($this->shop->shippingQuotes, new SofiaCalendar(), $this->shop->clock);
    }

    public function testAScheduleHoldsUntilItsCutoffAndWorkingDaysUntilMidnight(): void
    {
        $sofia = new \DateTimeZone('Europe/Sofia');
        $schedule = new DeliverySchedule([1, 2, 3, 4, 5], 1, '14:00', 7, ['2026-11-06']);

        $before = $schedule->estimate(new \DateTimeImmutable('2026-11-04 10:40', $sofia));
        self::assertNotNull($before);
        self::assertSame(['2026-11-05', '2026-11-05', '2026-11-04 14:00', true], [$before->from, $before->to, $before->until->format('Y-m-d H:i'), $before->cutoff], 'Wednesday before the cutoff: tomorrow');
        $after = $schedule->estimate(new \DateTimeImmutable('2026-11-05 15:00', $sofia));
        self::assertNotNull($after);
        self::assertSame(['2026-11-09', '2026-11-06 00:00', false], [$after->from, $after->until->format('Y-m-d H:i'), $after->cutoff], 'Thursday after it: not Friday, closed, nor the weekend');
        self::assertNull(new DeliverySchedule([6], 0, null, 2)->estimate(new \DateTimeImmutable('2026-11-02 09:00', $sofia)), 'no Saturday among the days ahead');

        $window = new DeliveryWindow(2, 4)->estimate(new \DateTimeImmutable('2026-11-06 18:00', $sofia));
        self::assertSame(['2026-11-10', '2026-11-12', '2026-11-07 00:00'], [$window->from, $window->to, $window->until->format('Y-m-d H:i')], 'Friday: two to four working days');
        $weekend = new DeliveryWindow(0, 1)->estimate(new \DateTimeImmutable('2026-11-07 09:00', $sofia));
        self::assertSame(['2026-11-09', '2026-11-10'], [$weekend->from, $weekend->to], 'Saturday: the days count from Monday');

        $this->expectException(ShippingRuleViolation::class);
        new DeliveryWindow(4, 2);
    }

    public function testTheSoonestCarrierThatDeliversThereSaysItForTheDaysAhead(): void
    {
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])], deliverySchedule: new SaveDeliverySchedule([1, 2, 3, 4, 5], 1, '14:00')));
        $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Europe', '9', regions: ['BG', 'DE'])], deliveryMinDays: 2, deliveryMaxDays: 4));
        $this->shop->carriers->create(new SaveCarrier('Express', active: false, rates: [new SaveShippingRate('Bulgaria', '15', regions: ['BG'])], deliveryMinDays: 0, deliveryMaxDays: 0));
        $this->shop->carriers->create(new SaveCarrier('Shop', pickupLocations: [new SavePickupLocation('sofia', 'Sofia', 'Vitosha 1')], deliveryMinDays: 0, deliveryMaxDays: 0));

        $bulgaria = $this->estimates->forParcel($this->query('BG'));
        self::assertSame([
            ['2026-11-06', '2026-11-06', '2026-11-05 14:00', '2026-11-05 14:00'],
            // After the cutoff the courier's Monday holds until Sunday's: the post's Monday comes no sooner.
            ['2026-11-09', '2026-11-09', '2026-11-08 14:00', '2026-11-08 14:00'],
            ['2026-11-10', '2026-11-10', '2026-11-09 14:00', '2026-11-09 14:00'],
        ], array_map(self::row(...), \array_slice($bulgaria, 0, 3)), 'the courier: its schedule, not the inactive express nor the shop\'s pickup');
        self::assertGreaterThanOrEqual(new \DateTimeImmutable('2026-11-13 08:40 UTC'), array_last($bulgaria)?->until, 'a week ahead and more, for cached pages');

        self::assertSame(['2026-11-09', '2026-11-11', '2026-11-06 00:00', null], self::row($this->estimates->forParcel($this->query('DE'))[0]), 'Germany: the post\'s working days, no cutoff');
        self::assertSame([], $this->estimates->forParcel($this->query('US')), 'no carrier delivers there');
    }

    public function testACarrierSavesTheWorkingDaysItTakes(): void
    {
        $saved = $this->shop->carriers->create(new SaveCarrier('Post', deliveryMinDays: 2, deliveryMaxDays: 4));
        self::assertSame([2, 4], [$saved->deliveryMinDays, $saved->deliveryMaxDays]);

        $exactly = $this->shop->carriers->update($saved->id, new SaveCarrier('Post', deliveryMaxDays: 3));
        self::assertSame([3, 3], [$exactly->deliveryMinDays, $exactly->deliveryMaxDays], 'one of them: exactly that');

        $cleared = $this->shop->carriers->update($saved->id, new SaveCarrier('Post'));
        self::assertSame([null, null], [$cleared->deliveryMinDays, $cleared->deliveryMaxDays]);

        $this->expectException(ShippingRuleViolation::class);
        $this->shop->carriers->update($saved->id, new SaveCarrier('Post', deliveryMinDays: 5, deliveryMaxDays: 2));
    }

    private function query(string $country): ShippingQuery
    {
        return new ShippingQuery($country, null, [new ParcelItem(1, '1')], '30');
    }

    /**
     * @return array{string, string, string, ?string} the days, and the moments in Sofia
     */
    private static function row(DeliveryEstimateView $estimate): array
    {
        $sofia = new \DateTimeZone('Europe/Sofia');

        return [$estimate->from, $estimate->to, $estimate->until->setTimezone($sofia)->format('Y-m-d H:i'), $estimate->orderBy?->setTimezone($sofia)->format('Y-m-d H:i')];
    }
}
