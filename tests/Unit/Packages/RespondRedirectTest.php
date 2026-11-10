<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Application\Host\RespondFunctions;
use OrillaCart\Packages\Application\RedirectTargets;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\ScriptError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `respond.redirect` sends the caller to the site itself or to a host the extension declared, never anywhere else. */
final class RespondRedirectTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function allowed(): iterable
    {
        yield 'a path of the site' => ['/thanks?order=1', '/thanks?order=1'];
        yield 'the site\'s own host' => ['https://shop.example/account', 'https://shop.example/account'];
        yield 'a declared host' => ['https://pay.example/checkout/1', 'https://pay.example/checkout/1'];
    }

    #[DataProvider('allowed')]
    public function testTheSiteAndDeclaredHostsAreAllowed(string $url, string $sent): void
    {
        $answer = self::redirect($url, 307);

        self::assertSame([ScriptResponse::REDIRECT, 307, $sent], [$answer->kind, $answer->status, $answer->url]);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'another host' => ['https://evil.example/login'];
        yield 'a declared host over http' => ['http://pay.example/checkout'];
        yield 'protocol-relative' => ['//evil.example/'];
        yield 'a backslash' => ['https://pay.example\\@evil.example/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'nothing' => [''];
    }

    #[DataProvider('refused')]
    public function testAnyOtherAddressIsRefused(string $url): void
    {
        $this->expectException(ScriptError::class);
        $this->expectExceptionMessage('A redirect goes to the site or to a host declared in needs.http');
        self::redirect($url);
    }

    public function testTheStatusDefaultsTo302(): void
    {
        self::assertSame(302, self::redirect('/thanks')->status);
    }

    private static function redirect(string $url, ?int $status = null): ScriptResponse
    {
        $targets = new class implements RedirectTargets {
            public function sameSite(string $url): ?string
            {
                return str_starts_with($url, '/') && !str_starts_with($url, '//') || str_starts_with($url, 'https://shop.example/') ? $url : null;
            }
        };
        $catalog = HostCatalog::of(new RespondFunctions($targets)->functions());
        $context = new Context($catalog, new Budget(), new Grant('pay', ['http'], ['pay.example']));
        $answer = $context->call('respond.redirect', $status === null ? [$url] : [$url, $status]);
        self::assertInstanceOf(ScriptResponse::class, $answer);

        return $answer;
    }
}
