<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\GuardedMailer;
use OrillaCart\Notifications\Application\Mailer;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Http\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Design 62: what never reaches the mail system. */
final class GuardedMailerTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'example.com' => ['a@example.com', true];
        yield 'a subdomain of example.org' => ['a@mail.example.org', true];
        yield 'example.net, in capitals' => ['A@EXAMPLE.NET', true];
        yield '.test' => ['buyer@shop.test', true];
        yield '.invalid' => ['x@nowhere.invalid', true];
        yield '.localhost' => ['x@localhost', true];
        yield '.example' => ['x@shop.example', true];
        yield 'a real domain' => ['ada@gmail.com', false];
        yield 'one that only looks like it' => ['ada@myexample.com', false];
        yield 'testing.com is real' => ['ada@testing.com', false];
    }

    #[DataProvider('addresses')]
    public function testTheReservedDomainsAreKnown(string $address, bool $reserved): void
    {
        self::assertSame($reserved, GuardedMailer::reserved($address));
    }

    public function testATestOrdersAndATestCustomersEmailsAreHeldAndLogged(): void
    {
        $shop = new TestShop();
        $sent = [];
        $inner = new class (static function (string $to) use (&$sent): void {
            $sent[] = $to;
        }) implements Mailer {
            public function __construct(private readonly \Closure $record) {}

            public function send(Email $email): bool
            {
                ($this->record)($email->to);

                return true;
            }
        };
        $logger = new MemoryLogger();
        $reserved = new class implements \OrillaCart\Notifications\Application\Undeliverable {
            public function address(string $address): bool
            {
                return GuardedMailer::reserved($address);
            }
        };
        $mailer = new GuardedMailer($inner, $reserved, $shop->orderRecords, $shop->accounts, $logger);
        $email = static fn(string $to, ?int $order = null): Email => new Email($to, 'Hi', '<p>Hi</p>', 'Hi', 'Shop', 'shop@shop.dev', null, 'test', $order);

        self::assertTrue($mailer->send($email('ada@gmail.com')));
        self::assertTrue($mailer->send($email('ada@example.com')), 'held is answered as sent: nothing to retry');
        $tester = $shop->accounts->register('tester@gmail.com', 'secret12', 'T', 'B');
        $shop->accounts->markTest($tester);
        self::assertTrue($mailer->send($email('tester@gmail.com')));

        self::assertSame(['ada@gmail.com'], $sent);
        self::assertSame(['the address is one no email goes to (reserved for examples and tests, or the site\'s own)', 'the customer was made to try the shop out'], array_map(static fn(array $record): mixed => $record['context']['why'] ?? null, $logger->records));
    }
}
