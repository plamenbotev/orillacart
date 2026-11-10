<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Http\PaymentNotificationAction;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Output\JsonResponder;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class PaymentNotificationActionTest extends TestCase
{
    public function testAFloodOfWebhooksFromOneAddressIsTurnedAwayBeforeAnyGatewayWorks(): void
    {
        $shop = new TestShop();
        $shop->content->add(20, 'E-book');
        $shop->products->save(20, new SaveProduct('8', 'virtual'));
        $shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
        $shop->rateLimiter->limits['payment_notifications'] = 2;
        $psr17 = new Psr17Factory();
        $action = new PaymentNotificationAction($shop->outcomes, new JsonResponder(new SerializerFactory()->create(), $psr17, $psr17), $shop->rateLimiter);
        $request = new ServerRequest('POST', '/payments/paypal/webhook', [], '{}', serverParams: ['REMOTE_ADDR' => '203.0.113.7'])->withAttribute('gateway', 'paypal');

        $statuses = [];
        for ($i = 0; $i < 3; ++$i) {
            try {
                $statuses[] = $action->handle($request)->getStatusCode();
            } catch (HttpException $e) {
                $statuses[] = $e->status;
            }
        }

        self::assertSame([400, 400, 429], $statuses);
        self::assertCount(2, array_filter($shop->logger->records, static fn(array $record): bool => str_starts_with($record['message'], 'A PayPal webhook was refused')), 'the third never reached the gateway');
    }
}
