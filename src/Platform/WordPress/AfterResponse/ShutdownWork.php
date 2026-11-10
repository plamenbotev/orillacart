<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\AfterResponse;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\Lifecycle\AfterResponse;
use Psr\Log\LoggerInterface;

/**
 * Runs deferred work on WordPress' shutdown action. The callback uses only this object, loaded at registration,
 * so a request that deferred nothing loads no class at shutdown, even after WP-CLI deleted the plugin's files.
 */
final class ShutdownWork implements AfterResponse, Registrar
{
    /** @var array<string, \Closure> */
    private array $work = [];
    /** Whether the container serves steps on another site (SiteKernels), which run the work at their end. */
    private bool $inSteps = false;

    public function __construct(private readonly LoggerInterface $logger) {}

    public function register(): void
    {
        if (\PHP_SAPI !== 'cli') {
            add_action('shutdown', $this->run(...), 1000);
        }
    }

    public function defer(string $key, \Closure $work): void
    {
        $this->work[$key] ??= $work;
    }

    /**
     * The container serves steps on another site than the request's (SiteKernels): its work runs at the end of
     * each step (runNow()), while the visitor waits, so it does not detach.
     */
    public function forSteps(): void
    {
        $this->inSteps = true;
    }

    public function detaches(): bool
    {
        return !$this->inSteps && \PHP_SAPI !== 'cli' && (\function_exists('fastcgi_finish_request') || \function_exists('litespeed_finish_request'));
    }

    public function run(): void
    {
        if ($this->work === []) {
            return;
        }
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        // The visitor has the answer; the work may take longer than the request was allowed.
        if (\function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        $this->runNow();
    }

    /**
     * Runs the work deferred so far, here and now, once: what it defers in turn is left (to cron), so work that
     * pushes more work cannot keep the request going.
     */
    public function runNow(): void
    {
        $waiting = $this->work;
        $this->work = [];
        foreach ($waiting as $key => $work) {
            try {
                $work();
            } catch (\Throwable $e) {
                $this->logger->error('Work after the response ({key}) failed: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
