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

    public function run(): void
    {
        if ($this->work === []) {
            return;
        }
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($this->work as $key => $work) {
            try {
                $work();
            } catch (\Throwable $e) {
                $this->logger->error('Work after the response ({key}) failed: {message}', ['key' => $key, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
        $this->work = [];
    }
}
