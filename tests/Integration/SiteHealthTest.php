<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\WordPress\Logging\DebugLogHealth;

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

    public function testALogTheWebServerHandsOutIsCriticalAndOneItRefusesIsNot(): void
    {
        $file = DebugLogHealth::logFile();
        if ($file === null || DebugLogHealth::webAddress($file, ABSPATH, site_url('/')) === null) {
            self::markTestSkipped('The test site logs outside the site.');
        }
        $existed = is_file($file);
        $written = !$existed || filesize($file) === 0;
        if ($written) {
            file_put_contents($file, "[10-Nov-2026 12:00:00 UTC] PHP Notice: a line\n", FILE_APPEND);
        }
        $start = (string) file_get_contents($file, false, null, 0, 512);
        try {
            $served = new DebugLogHealth(static fn(string $url): string => $start)->check();
            self::assertSame('critical', $served->status);

            $refused = new DebugLogHealth(static fn(string $url): string => '')->check();
            self::assertSame('good', $refused->status);

            $aPageOfItsOwn = new DebugLogHealth(static fn(string $url): string => '<!doctype html><title>Not found</title>')->check();
            self::assertSame('good', $aPageOfItsOwn->status);
        } finally {
            if ($written) {
                $existed ? file_put_contents($file, '') : unlink($file);
            }
        }
    }
}
