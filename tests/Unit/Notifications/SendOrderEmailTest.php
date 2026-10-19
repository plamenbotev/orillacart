<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use Nyholm\Psr7\ServerRequest;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use PHPUnit\Framework\TestCase;

final class SendOrderEmailTest extends TestCase
{
    public function testOnlyEmailsAboutTheStatusAreSentAgain(): void
    {
        self::assertSame('order_shipped', $this->map('order_shipped')->kind);
        self::assertSame('new_order', $this->map('new_order')->kind);

        foreach (['order_refunded', 'customer_note', 'order_attention'] as $kind) {
            try {
                $this->map($kind);
                self::fail("{$kind} needs what it told of, which the order alone does not hold.");
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
    }

    private function map(string $kind): SendOrderEmail
    {
        $serializers = new SerializerFactory();
        $body = ['kind' => $kind];

        return new RequestMapper($serializers->create(), new ValidatorFactory()->create(), $serializers->nameConverter())
            ->body(new ServerRequest('POST', '/orders/1/emails', ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR))->withParsedBody($body), SendOrderEmail::class);
    }
}
