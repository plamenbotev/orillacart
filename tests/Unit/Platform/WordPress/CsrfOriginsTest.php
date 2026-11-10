<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Security\WordPressCsrfProtection;
use PHPUnit\Framework\TestCase;

/**
 * The origins a browser's request may name: the site's own address, its port too.
 */
final class CsrfOriginsTest extends TestCase
{
    public function testASiteOnAPortIsItsOwnOriginAndTheSameHostOnAnotherPortIsNot(): void
    {
        // What WordPress lists for the site: the host, without the port.
        $listed = ['http://localhost', 'https://localhost'];

        $origins = WordPressCsrfProtection::origins('http://localhost:8888', 'http://localhost:8888', $listed);

        self::assertSame(['http://localhost:8888'], $origins);
        self::assertNotContains('http://localhost', $origins, 'another service on the same host');
    }

    public function testASiteWithoutAPortTakesBothSchemesAsWordPressDoes(): void
    {
        $origins = WordPressCsrfProtection::origins('https://shop.test/', 'https://shop.test/wp', ['http://shop.test', 'https://shop.test']);

        self::assertSame(['http://shop.test', 'https://shop.test'], $origins);
    }

    public function testTheSchemesOwnPortIsNoPortAndAnOriginTheSiteAddedCounts(): void
    {
        $origins = WordPressCsrfProtection::origins('https://Shop.test:443', 'https://admin.shop.test:8443/wp', ['http://shop.test', 'https://shop.test', 'https://Front.example:444/']);

        self::assertSame(['http://shop.test', 'https://shop.test', 'https://admin.shop.test:8443', 'https://front.example:444'], $origins);
        self::assertSame('https://shop.test', WordPressCsrfProtection::origin('HTTPS://Shop.test:443/'));
        self::assertSame('http://shop.test:443', WordPressCsrfProtection::origin('http://shop.test:443'));
    }
}
