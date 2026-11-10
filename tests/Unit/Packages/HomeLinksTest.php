<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Extension\Home\HomeLine;
use OrillaCart\Extension\Home\HomeTile;
use OrillaCart\Extension\Home\HomeUrl;
use OrillaCart\Packages\Application\Host\ContractFunctions;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\ScriptError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A Home line or tile leads to a page of the admin or the site, or to an http(s) URL; never to a script. */
final class HomeLinksTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function urls(): iterable
    {
        yield 'a Shop page' => ['orders?status=processing', true, false];
        yield 'a Shop page alone' => ['stock', true, false];
        yield 'an admin page' => ['admin.php?page=acme&tab=a', true, false];
        yield 'a path of the site' => ['/wp-admin/edit.php?post_type=page', true, false];
        yield 'https' => ['https://shop.example/returns', false, true];
        yield 'http' => ['http://shop.example/returns', false, true];
        yield 'javascript that names .php' => ['javascript:alert(1)//.php', false, false];
        yield 'javascript in capitals' => ['JavaScript:alert(1)', false, false];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', false, false];
        yield 'protocol-relative' => ['//evil.example/x.php', false, false];
        yield 'a backslash path' => ['/\\evil.example', false, false];
        yield 'a tab a browser drops' => ["/\t/evil.example", false, false];
        yield 'a line break in the scheme' => ["java\nscript:alert(1)", false, false];
        yield 'https without a host' => ['https:/\\evil.example', false, false];
        yield 'a page with a scheme after it' => ['x.php:alert(1)', false, false];
        yield 'markup in the query' => ['orders?x="><script>', false, false];
    }

    #[DataProvider('urls')]
    public function testOnlyPagesPathsAndHttpUrlsAreLinks(string $url, bool $relative, bool $absolute): void
    {
        self::assertSame([$relative, $absolute], [HomeUrl::relative($url), HomeUrl::absolute($url)]);
    }

    public function testAnHttpUrlIsALinkOnlyOnTheSitesOwnHosts(): void
    {
        $sameSite = static fn(string $url): ?string => str_starts_with($url, 'https://shop.example/') ? $url : null;

        self::assertSame('https://shop.example/returns', HomeUrl::link('https://shop.example/returns', $sameSite));
        self::assertSame('', HomeUrl::link('https://evil.example/login', $sameSite));
        self::assertSame('orders?status=processing', HomeUrl::link('orders?status=processing', $sameSite));
        self::assertSame('', HomeUrl::link('javascript:alert(1)//.php', static fn(string $url): string => $url), 'the site never vouches for a script');
        self::assertSame('', HomeUrl::link('', $sameSite));
    }

    public function testAScriptIsToldWhenItsLinkIsNoLink(): void
    {
        $tile = self::call('home.tile', ['Open', '3', '', 'admin.php?page=acme']);
        self::assertInstanceOf(HomeTile::class, $tile);
        self::assertSame('admin.php?page=acme', $tile->url);
        $line = self::call('home.line', ['Waits', 'https://shop.example/x', 'info']);
        self::assertInstanceOf(HomeLine::class, $line);
        self::assertSame('https://shop.example/x', $line->url);
        $none = self::call('home.line', ['Waits', null]);
        self::assertInstanceOf(HomeLine::class, $none);
        self::assertSame('', $none->url);

        $this->expectException(ScriptError::class);
        $this->expectExceptionMessage('home.tile takes a url that is a Shop page');
        self::call('home.tile', ['Open', '3', '', 'javascript:alert(1)//.php']);
    }

    /** @param list<mixed> $arguments */
    private static function call(string $function, array $arguments): mixed
    {
        return new Context(HostCatalog::of(new ContractFunctions()->functions()), new Budget(), new Grant('trade', [], []))->call($function, $arguments);
    }
}
