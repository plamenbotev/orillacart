<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Reports\Domain\CollectedTax;
use OrillaCart\Reports\Domain\GivenRefund;
use OrillaCart\Reports\Domain\Interval;
use OrillaCart\Reports\Domain\OrderFacts;
use OrillaCart\Reports\Domain\Period;
use OrillaCart\Reports\Domain\SoldLine;
use OrillaCart\Reports\Domain\SoldOrder;
use OrillaCart\Reports\Domain\UsedCoupon;
use OrillaCart\Reports\Infrastructure\Doctrine\DbalReportQueries;
use OrillaCart\Reports\Infrastructure\Doctrine\DbalReportStore;
use OrillaCart\Reports\Infrastructure\Doctrine\ReportTables;

/**
 * The reports' SQL on MySQL: sales of paid orders in the shop's currency, refunds on their own day, products by
 * what they brought, categories from WordPress, and new customers told from returning ones.
 */
final class ReportQueriesTest extends RestTestCase
{
    /** Order ids no test site uses. */
    private const array ORDERS = [990001, 990002, 990003, 990004, 990005];

    private DbalReportStore $store;
    private DbalReportQueries $queries;
    private int $product;
    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = new ReportTables(new EntityManagerFactory(DatabaseConfig::fromWordPress(), new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create());
        $this->store = new DbalReportStore($tables);
        $this->queries = new DbalReportQueries($tables);
        $this->store->replace(self::ORDERS, []);
        $this->product = (int) wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Report lamp', 'post_status' => 'publish']);
        $term = wp_insert_term('Report lights ' . uniqid(), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        $this->category = (int) $term['term_id'];
        wp_set_object_terms($this->product, [$this->category], ProductCategoryTaxonomy::NAME);
    }

    protected function tearDown(): void
    {
        $this->store->replace(self::ORDERS, []);
        wp_delete_post($this->product, true);
        wp_delete_term($this->category, ProductCategoryTaxonomy::NAME);
        parent::tearDown();
    }

    public function testSalesCountPaidOrdersInTheShopsCurrencyAndRefundsOnTheirOwnDay(): void
    {
        $this->store->replace(self::ORDERS, [
            $this->facts(990001, '2030-03-02', 'c7', '100', refund: ['2030-03-10', '30']),
            $this->facts(990002, '2030-03-03', 'c7', '50', coupon: ['SPRING', '5']),
            $this->facts(990003, '2030-03-09', 'eguest@example.com', '20'),
            $this->facts(990004, '2030-03-09', 'c8', '999', counted: false),
            $this->facts(990005, '2030-03-09', 'c9', '70', currency: 'USD'),
        ]);
        $march = new Period(new \DateTimeImmutable('2030-03-01'), new \DateTimeImmutable('2030-03-31'));

        $totals = $this->queries->totals($march, 'EUR');
        self::assertSame([3, 6, '170', '5', '30', '135', 2, 2], [$totals->orders, $totals->items, $totals->gross, $totals->discounts, $totals->refunds, $totals->net, $totals->customers, $totals->newCustomers]);
        self::assertSame(['EUR', 'USD'], $this->queries->currencies($march));

        $weeks = $this->queries->series($march, Interval::Week, 'EUR');
        self::assertSame(['2030-02-25', '2030-03-04'], array_keys($weeks), 'weeks start on Monday; the third week had no sales');
        self::assertSame([[2, '150', '0'], [1, '20', '30']], array_map(static fn(array $week): array => [$week['orders'], $week['gross'], $week['refunds']], array_values($weeks)), 'the refund counts in the week it was made');

        $later = new Period(new \DateTimeImmutable('2030-03-03'), new \DateTimeImmutable('2030-03-31'));
        self::assertSame(['new' => 1, 'returning' => 1], array_intersect_key(array_reduce($this->queries->customerSeries($later, Interval::Month, 'EUR'), static fn(array $sum, array $month): array => ['new' => $sum['new'] + $month['new'], 'returning' => $sum['returning'] + $month['returning']], ['new' => 0, 'returning' => 0]), ['new' => 0, 'returning' => 0]), 'customer 7 ordered before');

        self::assertSame([['product_id' => $this->product, 'name' => 'Report lamp', 'quantity' => 6, 'net' => '165', 'orders' => 3]], $this->queries->products($march, 'EUR', 'net', 10));
        self::assertSame([['category_id' => $this->category, 'quantity' => 6, 'net' => '165', 'orders' => 3]], array_map(static fn(array $row): array => array_diff_key($row, ['name' => 0]), $this->queries->categories($march, 'EUR', 10)));
        self::assertSame([['code' => 'SPRING', 'orders' => 1, 'amount' => '5']], $this->queries->coupons($march, 'EUR', 10));
        self::assertSame([['key' => 'BG', 'orders' => 3, 'total' => '170']], $this->queries->breakdown($march, 'EUR', 'country', 5));
        self::assertSame(['Ada Lovelace', 2, '150'], [($top = $this->queries->topCustomers($march, 'EUR', 1)[0])['name'], $top['orders'], $top['total']]);

        $this->store->replace([990001], []);
        self::assertSame([2, '0'], [$this->queries->totals($march, 'EUR')->orders, $this->queries->totals($march, 'EUR')->refunds], 'a deleted order takes its refunds with it');
    }

    public function testAYearBeforeComparesEachDayWithTheSameDayOverALeapDay(): void
    {
        $this->store->replace(self::ORDERS, [
            $this->facts(990001, '2027-02-28', 'c7', '40'),
            $this->facts(990002, '2027-03-01', 'c7', '100'),
            $this->facts(990003, '2028-03-01', 'c7', '50'),
        ]);
        $this->actAs('administrator');

        $report = $this->data($this->request('GET', '/reports/sales?from=2028-02-28&to=2028-03-01&interval=day&compare=year'));

        $series = $report['series'];
        self::assertIsArray($series);
        self::assertSame(
            [['2028-02-28', '0', '40'], ['2028-02-29', '0', null], ['2028-03-01', '50', '100']],
            array_map(static fn(mixed $day): array => \is_array($day) ? [$day['date'] ?? null, $day['net'] ?? null, $day['previous_net'] ?? null] : [], $series),
            'no 29 February a year before, and 1 March against 1 March',
        );
    }

    public function testTaxesAddUpByJurisdictionAndRateWithExemptSalesApart(): void
    {
        $tax = static fn(int $order, string $code, string $level, string $percent, string $kind, string $taxable, string $amount, string $refunded = '0', bool $exempt = false, bool $counted = true): CollectedTax => new CollectedTax(
            $order,
            new \DateTimeImmutable('2030-04-02'),
            $counted,
            'USD',
            'US',
            'CA',
            $level,
            $code,
            $code === 'US-CA' ? 'California' : 'Los Angeles',
            $percent,
            $kind,
            $taxable,
            $amount,
            $refunded,
            $exempt,
        );
        $this->store->replace(self::ORDERS, [
            $this->facts(990001, '2030-04-02', 'c7', '100', currency: 'USD', taxes: [$tax(990001, 'US-CA', 'region', '6', 'item', '100', '6', '1.2'), $tax(990001, 'CA-LA', 'local', '2.5', 'item', '100', '2.5'), $tax(990001, 'US-CA', 'region', '6', 'shipping', '10', '0.6')]),
            $this->facts(990002, '2030-04-02', 'c8', '40', currency: 'USD', taxes: [$tax(990002, 'US-CA', 'region', '6', 'item', '40', '0', exempt: true)]),
            $this->facts(990003, '2030-04-02', 'c9', '50', currency: 'USD', counted: false, taxes: [$tax(990003, 'US-CA', 'region', '6', 'item', '50', '3', counted: false)]),
        ]);

        $rows = $this->queries->taxes(new Period(new \DateTimeImmutable('2030-04-01'), new \DateTimeImmutable('2030-04-30')), 'USD');

        self::assertSame([
            ['US-CA', 'region', '6', 2, '110', '40', '6.6', '1.2'],
            ['CA-LA', 'local', '2.5', 1, '100', '0', '2.5', '0'],
        ], array_map(static fn(array $row): array => [$row['code'], $row['level'], $row['percent'], $row['orders'], $row['taxable'], $row['exempt'], $row['tax'], $row['refunded']], $rows), 'the state first, then its places; unpaid orders do not count');
    }

    /**
     * @param array{string, string}|null $refund
     * @param array{string, string}|null $coupon
     * @param list<CollectedTax>         $taxes
     */
    private function facts(int $id, string $day, string $customer, string $gross, bool $counted = true, string $currency = 'EUR', ?array $refund = null, ?array $coupon = null, array $taxes = []): OrderFacts
    {
        $date = new \DateTimeImmutable($day);
        $discount = $coupon[1] ?? '0';

        return new OrderFacts(
            new SoldOrder($id, $date, $date->setTime(10, 0), $counted, $counted ? 'processing' : 'pending_payment', $currency, $customer, str_starts_with($customer, 'c') ? (int) substr($customer, 1) : null, 'Ada Lovelace', 'BG', 'Bank transfer', '', 2, $gross, $discount, '0', '0', $gross),
            [new SoldLine($id, $date, $counted, $currency, $this->product, null, 'Report lamp', 2, (string) ((float) $gross - (float) $discount))],
            $coupon === null ? [] : [new UsedCoupon($id, $date, $counted, $currency, $coupon[0], $coupon[1])],
            $refund === null ? [] : [new GivenRefund($id, $id, new \DateTimeImmutable($refund[0]), $currency, $refund[1])],
            $taxes,
        );
    }
}
