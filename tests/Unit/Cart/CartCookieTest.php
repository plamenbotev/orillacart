<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Cart;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Tests\Fixtures\Http\FakeAuthorization;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The cart cookie is set at the site's own cookie path, so the shops of a network in folders of one domain do not
 * overwrite each other's cart.
 */
final class CartCookieTest extends TestCase
{
    public function testWithoutASitePathTheCookieCoversTheHost(): void
    {
        self::assertFalse(\defined('COOKIEPATH'), 'unit tests load no WordPress');
        self::assertSame('/', CartCookie::path());
        self::assertSame('', CartCookie::domain());

        $header = $this->give(str_repeat('a', 64), 'http://shop.example/wp-json/orillacart/v1/cart');

        self::assertStringStartsWith('orillacart_cart=' . str_repeat('a', 64) . '; Path=/; Max-Age=', $header);
        self::assertStringEndsWith('; HttpOnly; SameSite=Lax', $header);
        self::assertStringNotContainsString('Domain=', $header);
        self::assertStringContainsString('; Secure', $this->give(str_repeat('a', 64), 'https://shop.example/wp-json/orillacart/v1/cart'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASiteInAFolderOfTheDomainSetsTheCookieAtItsOwnPath(): void
    {
        \define('COOKIEPATH', '/shop-b/');
        \define('COOKIE_DOMAIN', 'network.example');

        self::assertSame('/shop-b/', CartCookie::path());
        self::assertSame('network.example', CartCookie::domain());
        self::assertStringContainsString('; Path=/shop-b/; Domain=network.example; Max-Age=', $this->give(str_repeat('b', 64), 'http://network.example/shop-b/wp-json/orillacart/v1/cart'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnEmptySitePathMeansTheWholeHost(): void
    {
        \define('COOKIEPATH', '');
        \define('COOKIE_DOMAIN', false);

        self::assertSame('/', CartCookie::path());
        self::assertSame('', CartCookie::domain());
    }

    private function give(string $token, string $url): string
    {
        $cookie = new CartCookie(new FakeAuthorization());
        $response = $cookie->give(new Response(), $token, new ServerRequest('POST', $url));

        return $response->getHeaderLine('Set-Cookie');
    }
}
