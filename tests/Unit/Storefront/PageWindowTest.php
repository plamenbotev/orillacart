<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Storefront;

use OrillaCart\Storefront\Infrastructure\WordPress\PageWindow;
use PHPUnit\Framework\TestCase;

final class PageWindowTest extends TestCase
{
    public function testAListOfManyPagesLinksTheFirstTheLastAndThoseAroundThisOne(): void
    {
        self::assertSame([1, 98, 99, 100, 101, 102, 834], PageWindow::numbers(100, 834));
        self::assertSame([1, 2, 3, 4, 5, 6, 7], PageWindow::numbers(4, 7), 'a gap of one page is that page');
        self::assertSame([1, 2, 3, 834], PageWindow::numbers(1, 834));
        self::assertSame([1, 832, 833, 834], PageWindow::numbers(834, 834));
        self::assertSame([1], PageWindow::numbers(1, 1));
        self::assertSame([], PageWindow::numbers(1, 0));
        self::assertSame([1, 4, 5, 6], PageWindow::numbers(99, 6), 'a page past the last is the last');
    }

    public function testAGapIsALinkWithoutANumber(): void
    {
        self::assertSame([
            ['number' => 1, 'url' => '/shop/'],
            ['number' => null, 'url' => ''],
            ['number' => 8, 'url' => '/shop/page/8/'],
            ['number' => 9, 'url' => '/shop/page/9/'],
            ['number' => 10, 'url' => '/shop/page/10/'],
        ], PageWindow::links(10, 10, static fn(int $number): string => $number === 1 ? '/shop/' : "/shop/page/{$number}/"));
    }

    public function testThePreviousAndNextPagesAreLinkedButNotPastEitherEnd(): void
    {
        $url = static fn(int $number): string => "/shop/page/{$number}/";

        self::assertSame(['previous' => '/shop/page/3/', 'next' => '/shop/page/5/'], PageWindow::steps(4, 7, $url));
        self::assertSame(['previous' => '', 'next' => '/shop/page/2/'], PageWindow::steps(1, 7, $url));
        self::assertSame(['previous' => '/shop/page/6/', 'next' => ''], PageWindow::steps(7, 7, $url));
        self::assertSame(['previous' => '/shop/page/7/', 'next' => ''], PageWindow::steps(99, 7, $url), 'a page past the last goes back to the last');
        self::assertSame(['previous' => '', 'next' => ''], PageWindow::steps(1, 0, $url));
    }
}
