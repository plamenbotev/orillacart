<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * Tools → Site Health on the wp-env site: each of the shop's checks runs and says something, and Info lists
 * what they know under OrillaCart.
 */
final class SiteHealthTest extends RestTestCase
{
    public function testTheShopsChecksRunAndInfoListsThem(): void
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        self::assertIsArray($tests);
        self::assertIsArray($tests['direct']);
        $ours = array_filter($tests['direct'], static fn(string $id): bool => str_starts_with($id, 'orillacart_'), ARRAY_FILTER_USE_KEY);
        self::assertSame(['orillacart_cache', 'orillacart_database', 'orillacart_downloads', 'orillacart_jobs', 'orillacart_paypal'], array_values(array_intersect(['orillacart_cache', 'orillacart_database', 'orillacart_downloads', 'orillacart_jobs', 'orillacart_paypal'], array_keys($ours))));

        $statuses = [];
        foreach ($ours as $id => $test) {
            self::assertIsArray($test);
            self::assertIsCallable($test['test']);
            $result = ($test['test'])();
            self::assertIsArray($result);
            self::assertSame($id, $result['test']);
            self::assertContains($result['status'], ['good', 'recommended', 'critical'], $id);
            self::assertNotSame('', $result['label'], $id);
            $statuses[$id] = $result['status'];
        }
        self::assertSame('good', $statuses['orillacart_database'] ?? null, 'the test site is migrated');

        $info = apply_filters('debug_information', []);
        self::assertIsArray($info);
        $section = $info['orillacart'] ?? null;
        self::assertIsArray($section);
        self::assertIsArray($section['fields']);
        self::assertArrayHasKey('jobs_runner', $section['fields']);
        self::assertArrayHasKey('database_pending', $section['fields']);
    }
}
