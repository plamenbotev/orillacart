<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Storefront;

use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\AddToCartWithoutScript;
use OrillaCart\Tests\Fixtures\Http\FakeCsrfProtection;
use PHPUnit\Framework\TestCase;

/** The add-to-cart form without JavaScript takes posts from the site's own pages only. */
final class AddToCartWithoutScriptTest extends TestCase
{
    public function testTheBrowserMustNameThisSite(): void
    {
        $csrf = new FakeCsrfProtection();

        self::assertTrue(AddToCartWithoutScript::fromThisSite(['HTTP_ORIGIN' => 'https://shop.test'], $csrf));
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_REFERER' => 'https://shop.test/lamp/'], $csrf), 'the Origin decides');
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_ORIGIN' => 'null'], $csrf), 'a sandboxed or hidden origin');
        self::assertTrue(AddToCartWithoutScript::fromThisSite(['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], $csrf), 'the site\'s own page under no-referrer');
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], $csrf), 'a sandboxed frame on another site');
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'same-site'], $csrf), 'another site on the same domain');
        self::assertTrue(AddToCartWithoutScript::fromThisSite(['HTTP_REFERER' => 'https://shop.test/product/lamp/?x=1'], $csrf));
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_REFERER' => 'https://shop.test.evil.test/lamp/'], $csrf));
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_REFERER' => 'https://shop.test:8443/lamp/'], $csrf), 'another port');
        self::assertFalse(AddToCartWithoutScript::fromThisSite(['HTTP_REFERER' => 'not an address'], $csrf));
        self::assertTrue(AddToCartWithoutScript::fromThisSite([], $csrf), 'a browser that names no page');
    }
}
