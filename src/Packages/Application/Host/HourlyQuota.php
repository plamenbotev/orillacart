<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\Http\Security\RateLimiter;

/**
 * What an extension may do in an hour across all its runs, where a run's own budget is not enough: a public
 * route that sends mail would otherwise relay it for anyone. Counted per extension by the shop's rate limiter
 * (guards `extension_emails`, 50 an hour, and `extension_http`, 500 an hour, changed with orillacart_rate_limits).
 * Over the cap the call is an `unavailable` failure the script sees, as a provider down for a while.
 */
final readonly class HourlyQuota
{
    public const string EMAILS = 'extension_emails';
    public const string HTTP = 'extension_http';

    public function __construct(private RateLimiter $limiter) {}

    /** Counts one more of the guard for the run's extension; fails the call when the hour's cap is reached. */
    public function take(Context $c, string $guard, string $what): void
    {
        $wait = $this->limiter->hit($guard, 'extension:' . $c->grant->extension);
        if ($wait !== null) {
            throw new ScriptFailure(ScriptFailure::UNAVAILABLE, \sprintf('The extension made its %s for this hour; try again in %d minutes', $what, max(1, (int) ceil($wait / 60))), ['retry_after' => $wait]);
        }
    }
}
