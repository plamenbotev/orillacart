<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Packages\Application\Host\PaymentFunctions;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\ScriptError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A payment sends the buyer over https; a script's gateway only to a host it declared. */
final class PaymentRedirectTest extends TestCase
{
    public function testAScriptedGatewaySendsTheBuyerToADeclaredHost(): void
    {
        $start = self::redirect('https://pay.example/session/1');

        self::assertSame(['https://pay.example/session/1', 'P1'], [$start->redirectUrl, $start->reference]);
        self::assertSame('https://PAY.example/x', self::redirect('https://PAY.example/x')->redirectUrl, 'a host in capitals is the same host');
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'javascript' => ['javascript:alert(document.cookie)'];
        yield 'a host it did not declare' => ['https://pay-example.evil/session'];
        yield 'a declared host over http' => ['http://pay.example/session'];
        yield 'protocol-relative' => ['//pay.example/session'];
        yield 'a backslash' => ['https://pay.example\\@evil.example/'];
        yield 'a space a browser drops' => ["https://pay.example\t.evil/"];
        yield 'nothing' => [''];
    }

    #[DataProvider('refused')]
    public function testAnyOtherAddressIsAnError(string $url): void
    {
        $this->expectException(ScriptError::class);
        $this->expectExceptionMessage('payment.redirect goes over https to a host declared in needs.http');
        self::redirect($url);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'https' => ['https://checkout.stripe.com/c/pay/1', true];
        yield 'https in capitals' => ['HTTPS://www.paypal.com/checkoutnow?token=1', true];
        yield 'http' => ['http://pay.example/', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,<script>1</script>', false];
        yield 'protocol-relative' => ['//pay.example/', false];
        yield 'no host' => ['https:///path', false];
        yield 'a backslash' => ['https:\\\\pay.example', false];
        yield 'a line break' => ["https://pay.example/\njavascript:1", false];
        yield 'a path' => ['/checkout', false];
    }

    #[DataProvider('addresses')]
    public function testEveryGatewaysRedirectIsHttps(string $url, bool $https): void
    {
        self::assertSame($https, Payments::isHttps($url));
    }

    private static function redirect(string $url): PaymentStart
    {
        $context = new Context(HostCatalog::of(new PaymentFunctions()->functions()), new Budget(), new Grant('pay', ['http'], ['pay.example']));
        $start = $context->call('payment.redirect', [$url, 'P1']);
        self::assertInstanceOf(PaymentStart::class, $start);

        return $start;
    }
}
