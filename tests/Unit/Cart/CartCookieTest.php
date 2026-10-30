<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Cart;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Tests\Fixtures\Http\FakeAuthorization;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The cart cookie is set at the site's own cookie path for the host alone, so the shops of a network, in folders of
 * one domain or on subdomains sharing COOKIE_DOMAIN, do not overwrite each other's cart; forgetting it expires it
 * everywhere an earlier build set it too.
 */
final class CartCookieTest extends TestCase
{
    public function testWithoutASitePathTheCookieCoversTheHost(): void
    {
        self::assertFalse(\defined('COOKIEPATH'), 'unit tests load no WordPress');
        self::assertSame('/', SiteCookies::path());
        self::assertSame([['path' => '/', 'domain' => '']], SiteCookies::everywhere());

        $header = $this->give(str_repeat('a', 64), 'http://shop.example/wp-json/orillacart/v1/cart');

        self::assertStringStartsWith('orillacart_cart=' . str_repeat('a', 64) . '; Path=/; Max-Age=', $header);
        self::assertStringEndsWith('; HttpOnly; SameSite=Lax', $header);
        self::assertStringNotContainsString('Domain=', $header);
        self::assertStringContainsString('; Secure', $this->give(str_repeat('a', 64), 'https://shop.example/wp-json/orillacart/v1/cart'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASiteInAFolderOfTheDomainSetsTheCookieAtItsOwnPathForTheHostAloneWhateverTheCookieDomain(): void
    {
        \define('COOKIEPATH', '/shop-b/');
        // A network sharing its login across subdomains or mapped domains: the shops must not share a cart.
        \define('COOKIE_DOMAIN', '.network.example');

        self::assertSame('/shop-b/', SiteCookies::path());
        $header = $this->give(str_repeat('b', 64), 'http://network.example/shop-b/wp-json/orillacart/v1/cart');
        self::assertStringContainsString('; Path=/shop-b/; Max-Age=', $header);
        self::assertStringNotContainsString('Domain=', $header);
        self::assertSame([
            ['path' => '/shop-b/', 'domain' => ''],
            ['path' => '/', 'domain' => ''],
            ['path' => '/shop-b/', 'domain' => '.network.example'],
            ['path' => '/', 'domain' => '.network.example'],
        ], SiteCookies::everywhere(), 'logging out expires it here and wherever an earlier build set it');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnANetworkTheSitesOwnFolderIsUsedNotTheNetworksCookiePath(): void
    {
        // WordPress's COOKIEPATH on a network is the network's path: one login for every site, but one cart too.
        \define('COOKIEPATH', '/');
        eval('function is_multisite(): bool { return true; } function get_site(): object { return (object) ["path" => "/shop-b/"]; }');

        self::assertSame('/shop-b/', SiteCookies::path());
        self::assertStringContainsString('; Path=/shop-b/; Max-Age=', $this->give(str_repeat('c', 64), 'http://network.example/shop-b/wp-json/orillacart/v1/cart'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnEmptySitePathMeansTheWholeHost(): void
    {
        \define('COOKIEPATH', '');
        \define('COOKIE_DOMAIN', false);

        self::assertSame('/', SiteCookies::path());
        self::assertSame([['path' => '/', 'domain' => '']], SiteCookies::everywhere());
    }

    private function give(string $token, string $url): string
    {
        $cookie = new CartCookie(new FakeAuthorization());
        $response = $cookie->give(new Response(), $token, new ServerRequest('POST', $url));

        return $response->getHeaderLine('Set-Cookie');
    }
}
