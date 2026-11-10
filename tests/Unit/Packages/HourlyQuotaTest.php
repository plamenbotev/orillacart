<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\Host\HourlyQuota;
use OrillaCart\Platform\WordPress\Security\TransientRateLimiter;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\Http\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/** An extension's mail and HTTP calls are capped per hour across its runs; a run's host time counts against it. */
final class HourlyQuotaTest extends TestCase
{
    public function testTheCapIsCountedPerExtensionAndFailsAsUnavailable(): void
    {
        $limiter = new class implements RateLimiter {
            /** @var array<string, int> */
            public array $hits = [];

            public function hit(string $guard, string $caller): ?int
            {
                $this->hits[$guard . '|' . $caller] = ($this->hits[$guard . '|' . $caller] ?? 0) + 1;

                return $this->hits[$guard . '|' . $caller] > 2 ? 600 : null;
            }
        };
        $quota = new HourlyQuota($limiter);
        $mailer = new Context(HostCatalog::of([]), new Budget(), new Grant('mailer'));
        $other = new Context(HostCatalog::of([]), new Budget(), new Grant('other'));

        $quota->take($mailer, HourlyQuota::EMAILS, 'emails');
        $quota->take($mailer, HourlyQuota::EMAILS, 'emails');
        $quota->take($other, HourlyQuota::EMAILS, 'emails');
        $quota->take($mailer, HourlyQuota::HTTP, 'HTTP calls');
        try {
            $quota->take($mailer, HourlyQuota::EMAILS, 'emails');
            self::fail('over the cap');
        } catch (ScriptFailure $e) {
            self::assertSame(ScriptFailure::UNAVAILABLE, $e->kind);
            self::assertSame('The extension made its emails for this hour; try again in 10 minutes', $e->getMessage());
            self::assertSame(['retry_after' => 600], $e->data);
        }
        self::assertSame(['extension_emails|extension:mailer' => 3, 'extension_emails|extension:other' => 1, 'extension_http|extension:mailer' => 1], $limiter->hits);
    }

    public function testTheShopsLimitsHoldBothCaps(): void
    {
        self::assertSame([50, 3600], TransientRateLimiter::limits()[HourlyQuota::EMAILS]);
        self::assertSame([500, 3600], TransientRateLimiter::limits()[HourlyQuota::HTTP]);
    }

    public function testTheTimeLeftShrinksAndRunsOut(): void
    {
        $context = new Context(HostCatalog::of([]), new Budget(seconds: 0.05));
        $left = $context->secondsLeft();
        self::assertGreaterThan(0.0, $left);
        self::assertLessThanOrEqual(0.05, $left);

        usleep(60000);
        $this->expectException(BudgetExceeded::class);
        $context->secondsLeft();
    }

    public function testAHostCallThatWaitedStopsTheRunAtTheNextCall(): void
    {
        $slow = new HostFunction('slow', static function (Context $c): bool {
            usleep(60000);

            return true;
        }, 0, 0, 'slow(): waits');
        $context = new Context(HostCatalog::of([$slow]), new Budget(seconds: 0.05));

        self::assertTrue($context->call('slow', []));
        $this->expectException(BudgetExceeded::class);
        $context->call('slow', []);
    }
}
