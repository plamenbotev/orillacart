<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Logging;

use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the web server hands out the log the shop writes to. WP_DEBUG_LOG puts it at wp-content/debug.log,
 * inside the site, where anyone who asks for that address reads what went wrong: paths, order numbers, what a
 * provider answered. The log is fetched over HTTP once a day, as a visitor would.
 */
final readonly class DebugLogHealth implements HealthCheck
{
    private const string CHECK_TRANSIENT = 'orillacart_debug_log_reachable';

    /**
     * @param \Closure(string): ?string|null $fetch the first bytes the address hands out, '' when it refuses, null
     *                                              when it could not be asked; over HTTP by default
     */
    public function __construct(private ?\Closure $fetch = null) {}

    public static function id(): string
    {
        return 'debug_log';
    }

    public static function label(): string
    {
        return __('The shop’s log', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $file = self::logFile();
        $url = $file === null ? null : self::webAddress($file, ABSPATH, site_url('/'));
        if ($file === null || $url === null) {
            return HealthStatus::good(__('The shop’s log is not inside the site', 'orillacart'));
        }
        if ($this->reachable($file, $url) === true) {
            return HealthStatus::critical(
                __('The web server hands out the shop’s log', 'orillacart'),
                \sprintf(
                    /* translators: 1: an address, 2: the wp-config.php line */
                    __('Anyone can read %1$s, which tells what went wrong in the shop and where its files are. Point the log outside the site in wp-config.php (%2$s), or deny the file in the web server’s configuration.', 'orillacart'),
                    $url,
                    "define( 'WP_DEBUG_LOG', '/path/outside/the/site/debug.log' );",
                ),
            );
        }

        return HealthStatus::good(__('The web server does not hand out the shop’s log', 'orillacart'));
    }

    public function info(): array
    {
        $file = self::logFile();

        return ['file' => ['label' => __('Log file', 'orillacart'), 'value' => $file ?? __('the server’s own', 'orillacart')]];
    }

    /**
     * The file PHP's error log goes to, as WordPress sets it: WP_DEBUG_LOG true is wp-content/debug.log, a path is
     * that file; otherwise PHP's error_log setting. Null where the log goes to the server's own (syslog, stderr).
     */
    public static function logFile(mixed $debugLog = null, ?string $contentDir = null, ?string $errorLog = null): ?string
    {
        $debugLog ??= \defined('WP_DEBUG_LOG') ? \constant('WP_DEBUG_LOG') : false;
        if ($debugLog === true || \in_array($debugLog, [1, '1'], true)) {
            return ($contentDir ?? (\defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '')) . '/debug.log';
        }
        if (\is_string($debugLog) && $debugLog !== '' && $debugLog !== '0' && strtolower($debugLog) !== 'false') {
            return $debugLog;
        }
        $errorLog ??= (string) \ini_get('error_log');

        return $errorLog !== '' && $errorLog !== 'syslog' && !str_starts_with($errorLog, 'php://') ? $errorLog : null;
    }

    /** The address the file has on the site when it lies inside the site's folder; null when it lies outside. */
    public static function webAddress(string $file, string $root, string $siteUrl): ?string
    {
        $file = self::normalise($file);
        $root = rtrim(self::normalise($root), '/') . '/';
        if (!str_starts_with($file, $root)) {
            return null;
        }

        return rtrim($siteUrl, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', substr($file, \strlen($root)))));
    }

    /** The path with "." and ".." resolved as far as it goes, so "wp-content/../debug.log" is told apart. */
    private static function normalise(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return str_replace('\\', '/', $real);
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && ($part !== '' || $parts === [])) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Whether the address hands out the file itself: what comes back starts as the file does, so a site that
     * answers every address with a page of its own is not taken for one that serves its log.
     */
    private function reachable(string $file, string $url): ?bool
    {
        $start = is_file($file) && is_readable($file) ? (string) file_get_contents($file, false, null, 0, 64) : '';
        if ($start === '') {
            // Nothing written yet: nothing to read either. The check runs again once there is.
            return null;
        }
        if ($this->fetch === null) {
            $cached = get_transient(self::CHECK_TRANSIENT);
            if (\is_array($cached) && ($cached['url'] ?? null) === $url && \is_bool($cached['reachable'] ?? null)) {
                return $cached['reachable'];
            }
        }
        $served = $this->fetch !== null ? ($this->fetch)($url) : self::fetch($url);
        if ($served === null) {
            return null;
        }
        $reachable = $served !== '' && str_starts_with($served, substr($start, 0, min(\strlen($start), \strlen($served))));
        if ($this->fetch === null) {
            set_transient(self::CHECK_TRANSIENT, ['url' => $url, 'reachable' => $reachable], DAY_IN_SECONDS);
        }

        return $reachable;
    }

    private static function fetch(string $url): ?string
    {
        // A few bytes are enough to tell: the log may be large.
        $response = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false, 'redirection' => 0, 'limit_response_size' => 512, 'headers' => ['Range' => 'bytes=0-511']]);
        if ($response instanceof \WP_Error) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);

        return \in_array(wp_remote_retrieve_response_code($response), [200, 206], true) && $body !== '' ? $body : '';
    }
}
