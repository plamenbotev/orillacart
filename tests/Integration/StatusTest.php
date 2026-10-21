<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The Status screen's report: the shop's checks, and what the shop, WordPress and the server are.
 */
final class StatusTest extends RestTestCase
{
    public function testShopManagersSeeTheChecksAndWhatTheShopRunsOn(): void
    {
        self::assertSame(401, $this->request('GET', '/status')->get_status());

        $this->actAs('administrator');
        $report = $this->data($this->request('GET', '/status'));

        self::assertIsArray($report['checks']);
        $ids = array_column($report['checks'], 'status', 'id');
        self::assertArrayHasKey('database', $ids);
        self::assertArrayHasKey('search', $ids);
        self::assertContains($ids['database'], ['good', 'recommended', 'critical']);
        self::assertIsArray($report['sections']);
        $fields = [];
        foreach ($report['sections'] as $section) {
            self::assertIsArray($section);
            self::assertIsArray($section['fields']);
            foreach ($section['fields'] as $field) {
                self::assertIsArray($field);
                self::assertIsString($field['label']);
                $fields[$field['label']] = $field['value'];
            }
        }
        self::assertSame(PHP_VERSION, $fields['PHP'] ?? null);
        self::assertArrayHasKey('OrillaCart version', $fields);
        self::assertArrayHasKey('Orders', $fields);
    }
}
