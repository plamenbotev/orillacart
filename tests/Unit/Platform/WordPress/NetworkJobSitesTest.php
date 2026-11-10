<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Jobs\Application\JobSites;
use OrillaCart\Platform\WordPress\NetworkJobSites;
use PHPUnit\Framework\TestCase;

final class NetworkJobSitesTest extends TestCase
{
    private const array LOADED = ['plugins' => ['erp-sync/erp-sync.php'], 'network' => ['cache/cache.php'], 'theme' => 'shop-child/shop'];

    public function testASiteWithTheWorkersPluginsAndThemeIsServed(): void
    {
        self::assertNull(NetworkJobSites::apartFor(self::LOADED, ['erp-sync/erp-sync.php'], 'shop-child/shop'));
    }

    public function testASiteWithOtherPluginsOrAnotherThemeIsLeftOutAndSaysWhy(): void
    {
        self::assertSame(JobSites::PLUGINS, NetworkJobSites::apartFor(self::LOADED, [], 'shop-child/shop'));
        self::assertSame(JobSites::THEME, NetworkJobSites::apartFor(self::LOADED, ['erp-sync/erp-sync.php'], 'other/other'), 'its templates and functions.php would be missing from its emails');
    }

    public function testTheWorkerIsOutdatedWhenTheBootingSitesPluginsOrThemeOrTheNetworksPluginsChange(): void
    {
        self::assertFalse(NetworkJobSites::changed(self::LOADED, ['erp-sync/erp-sync.php'], ['cache/cache.php'], 'shop-child/shop'));
        self::assertTrue(NetworkJobSites::changed(self::LOADED, [], ['cache/cache.php'], 'shop-child/shop'), 'an add-on turned off since it started');
        self::assertTrue(NetworkJobSites::changed(self::LOADED, ['erp-sync/erp-sync.php'], [], 'shop-child/shop'), 'one for the whole network');
        self::assertTrue(NetworkJobSites::changed(self::LOADED, ['erp-sync/erp-sync.php'], ['cache/cache.php'], 'shop/shop'), 'another theme');
    }
}
