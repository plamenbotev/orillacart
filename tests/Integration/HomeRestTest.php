<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The Home screen's one answer (design 52): figures, what needs the shop manager, the latest orders, and what
 * add-ons put there; for shop managers only.
 */
final class HomeRestTest extends RestTestCase
{
    public function testTheHomeScreenGetsItsFiguresTheLinesThatWaitAndTheLatestOrders(): void
    {
        $this->actAs('administrator');

        $response = $this->request('GET', '/home');

        self::assertSame(200, $response->get_status());
        $home = $this->data($response);
        self::assertSame(['today', 'week', 'currency', 'needs_you', 'setup_finished', 'recent', 'tiles'], array_keys($home));
        self::assertIsArray($home['today']);
        self::assertSame(['orders', 'net', 'average_order', 'items'], array_keys($home['today']));
        self::assertIsArray($home['week']);
        self::assertIsArray($home['week']['series']);
        self::assertCount(7, $home['week']['series'], 'a point a day, the week through');
        self::assertIsArray($home['needs_you']);
        foreach ($home['needs_you'] as $line) {
            self::assertIsArray($line);
            self::assertSame(['kind', 'text', 'count', 'url', 'tone'], array_keys($line));
            self::assertContains($line['tone'], ['attention', 'warning', 'info']);
        }
        self::assertIsBool($home['setup_finished']);
        self::assertIsArray($home['recent']);
        self::assertLessThanOrEqual(5, \count($home['recent']));
        // The test add-on registers nothing for the Home screen.
        self::assertSame([], $home['tiles']);
    }

    public function testTheHomeScreenIsForShopManagers(): void
    {
        $this->actAs('subscriber');

        self::assertSame(403, $this->request('GET', '/home')->get_status());
    }
}
