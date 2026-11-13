<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Extension\Order\CreatesOrders;
use OrillaCart\Extension\Order\NewOrderRequest;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\Registry;
use OrillaCart\Packages\Application\Host\HourlyQuota;
use OrillaCart\Packages\Infrastructure\WordPress\ShopFunctions;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\Http\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/** An order an extension places with the customer emailed sends mail as shop.email.send does, and is held to the same caps. */
final class OrderMailQuotaTest extends TestCase
{
    /** @var list<NewOrderRequest> */
    private array $placed = [];
    /** @var list<string> */
    private array $hits = [];

    public function testEmailingTheCustomerNeedsTheMailPermission(): void
    {
        $context = $this->context(['orders:create']);

        try {
            $context->call('shop.orders.create', [self::order(true)]);
            self::fail('mail without email:send');
        } catch (ScriptError $e) {
            self::assertStringContainsString('email:send', $e->getMessage());
        }
        self::assertSame([], $this->placed);
        self::assertSame([], $this->hits);
    }

    public function testEmailingTheCustomerCountsAgainstTheRunAndTheHour(): void
    {
        $context = $this->context(['orders:create', 'email:send']);

        $this->place($context, self::order(true));

        self::assertCount(1, $this->placed);
        self::assertSame(1, $context->used()['emails']);
        self::assertSame([HourlyQuota::EMAILS], $this->hits);
    }

    public function testOverTheRunsEmailsNoOrderIsPlaced(): void
    {
        $context = $this->context(['orders:create', 'email:send'], new Budget(emails: 0));

        $this->expectException(BudgetExceeded::class);
        try {
            $context->call('shop.orders.create', [self::order(true)]);
        } finally {
            self::assertSame([], $this->placed);
        }
    }

    public function testAnOrderThatMailsNoOneCountsNoMail(): void
    {
        $context = $this->context(['orders:create']);

        $this->place($context, self::order(false));
        $this->place($context, ['test' => true] + self::order(true));

        self::assertCount(2, $this->placed);
        self::assertSame(0, $context->used()['emails']);
        self::assertSame([], $this->hits);
    }

    /** @param array<string, mixed> $order */
    private function place(Context $context, array $order): void
    {
        try {
            $context->call('shop.orders.create', [$order]);
        } catch (ScriptFailure) {
            // The stand-in shop refuses every order once it has seen it.
        }
    }

    /** @return array<string, mixed> */
    private static function order(bool $emailCustomer): array
    {
        return ['email' => 'anyone@example.test', 'billing' => ['first_name' => 'A', 'last_name' => 'B', 'street' => 'S 1', 'city' => 'C', 'postcode' => '1000', 'country' => 'DE'], 'items' => [['product_id' => 1, 'quantity' => 1]], 'email_customer' => $emailCustomer];
    }

    /** @param list<string> $permissions */
    private function context(array $permissions, ?Budget $budget = null): Context
    {
        $orders = new class (function (NewOrderRequest $request): void {
            $this->placed[] = $request;
        }) implements CreatesOrders {
            public function __construct(private readonly \Closure $record) {}

            public function create(NewOrderRequest $request): OrderSummary
            {
                ($this->record)($request);

                throw new \InvalidArgumentException('Not placed here.');
            }

            public function quote(NewOrderRequest $request): \OrillaCart\Extension\Order\OrderQuote
            {
                throw new \InvalidArgumentException('Not quoted here.');
            }
        };
        $registry = self::createStub(Registry::class);
        $registry->method('orderCreation')->willReturn($orders);
        $limiter = new class (function (string $guard): void {
            $this->hits[] = $guard;
        }) implements RateLimiter {
            public function __construct(private readonly \Closure $record) {}

            public function hit(string $guard, string $caller, ?array $limit = null): ?int
            {
                ($this->record)($guard);

                return null;
            }
        };
        $functions = new ShopFunctions(static fn(): Registry => $registry, new HourlyQuota($limiter));

        return new Context(HostCatalog::of($functions->functions()), $budget ?? new Budget(), new Grant('placer', $permissions));
    }
}
