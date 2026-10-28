<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Version;
use OrillaCart\Payment\Application\Gateway\BankTransfer;
use OrillaCart\Payment\Application\Gateway\CashOnDelivery;
use OrillaCart\Platform\WordPress\Extensions\WordPressExtensions;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class WordPressExtensionsTest extends TestCase
{
    public function testTheShopsOwnGatewaysComeFirstAndATakenOrMalformedIdIsLeftOutAndLogged(): void
    {
        $logger = new MemoryLogger();
        $extensions = new WordPressExtensions([new CashOnDelivery(), new BankTransfer(), self::gateway('bank_transfer'), self::gateway('Not An Id')], $logger, static fn() => throw new \LogicException('No orders in this test.'), static fn() => throw new \LogicException('No products in this test.'), static fn() => throw new \LogicException('No order prices in this test.'), static fn() => throw new \LogicException('No product languages in this test.'));

        self::assertSame(['cash_on_delivery', 'bank_transfer'], array_map(static fn(PaymentGateway $gateway): string => $gateway->id(), $extensions->paymentGateways()));
        self::assertSame(['error', 'error'], array_column($logger->records, 'level'));
        self::assertSame(Version::CURRENT, $extensions->version());
    }

    public function testGatewaysAreRegisteredOnlyOnTheAction(): void
    {
        $extensions = new WordPressExtensions([], new MemoryLogger(), static fn() => throw new \LogicException('No orders in this test.'), static fn() => throw new \LogicException('No products in this test.'), static fn() => throw new \LogicException('No order prices in this test.'), static fn() => throw new \LogicException('No product languages in this test.'));

        $this->expectException(\LogicException::class);
        $extensions->paymentGateway(self::gateway('late'));
    }

    private static function gateway(string $id): PaymentGateway
    {
        return new readonly class ($id) implements PaymentGateway {
            public function __construct(private string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function defaultName(): string
            {
                return 'Gateway ' . $this->id;
            }

            public function settings(): array
            {
                return [];
            }

            public function notReady(Settings $settings, string $currency, int $decimals): ?string
            {
                return null;
            }

            public function start(PaymentSession $session, Settings $settings): PaymentStart
            {
                return PaymentStart::offline();
            }
        };
    }
}
