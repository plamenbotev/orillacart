<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Files;

use OrillaCart\Shared\Files\DeliveryCheck;
use OrillaCart\Shared\Files\DeliveryMethod;
use OrillaCart\Shared\Files\DeliverySettings;
use OrillaCart\Shared\Files\FileDelivery;
use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Keeps the delivery method in an option and tests a method before keeping it: a probe file is stored, the site
 * asks itself over HTTP for ?orillacart_delivery_probe=TOKEN, DeliveryProbe answers with only the hand-off header,
 * and the method works when the web server sent the probe's content in its place.
 */
final readonly class WebServerFileDelivery implements FileDelivery, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_download_delivery';
    public const string PROBE_QUERY = 'orillacart_delivery_probe';
    private const string PROBE_TRANSIENT = 'orillacart_delivery_probe_';
    private const string PROBE_CONTENT = 'orillacart delivery probe';

    public function __construct(private PrivateFiles $files) {}

    public function settings(): DeliverySettings
    {
        $saved = get_option(self::OPTION);
        if (!\is_array($saved)) {
            return new DeliverySettings();
        }
        $method = DeliveryMethod::tryFrom(\is_string($saved['method'] ?? null) ? $saved['method'] : '') ?? DeliveryMethod::Php;
        $prefix = \is_string($saved['prefix'] ?? null) ? $saved['prefix'] : DeliverySettings::DEFAULT_PREFIX;

        return new DeliverySettings($method, $prefix);
    }

    public function change(DeliverySettings $settings): DeliveryCheck
    {
        $settings = new DeliverySettings($settings->method, self::prefix($settings->internalPrefix));
        $check = $settings->method === DeliveryMethod::Php
            ? new DeliveryCheck(true, __('PHP sends the files.', 'orillacart'), '')
            : $this->test($settings);
        if ($check->works) {
            update_option(self::OPTION, ['method' => $settings->method->value, 'prefix' => $settings->internalPrefix], false);
        }

        return $check;
    }

    public function handOff(string $key): ?string
    {
        return $this->header($this->settings(), $key);
    }

    /**
     * The probe's answer: the hand-off header for the test file, when the token is one this site gave out.
     *
     * @internal for DeliveryProbe
     */
    public function probe(string $token): ?string
    {
        $probe = preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? get_transient(self::PROBE_TRANSIENT . $token) : false;
        if (!\is_array($probe) || !\is_string($probe['method'] ?? null) || !\is_string($probe['prefix'] ?? null) || !\is_string($probe['key'] ?? null)) {
            return null;
        }
        $method = DeliveryMethod::tryFrom($probe['method']);

        return $method === null ? null : $this->header(new DeliverySettings($method, $probe['prefix']), $probe['key']);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }

    private function test(DeliverySettings $settings): DeliveryCheck
    {
        $temporary = (string) tempnam(get_temp_dir(), 'orillacart-probe');
        file_put_contents($temporary, self::PROBE_CONTENT);
        $stored = $this->files->store($temporary, 'probe.txt');
        $rule = $this->rule($settings, $stored->key);
        $token = bin2hex(random_bytes(16));
        set_transient(self::PROBE_TRANSIENT . $token, ['method' => $settings->method->value, 'prefix' => $settings->internalPrefix, 'key' => $stored->key], MINUTE_IN_SECONDS);
        try {
            $response = wp_remote_get(add_query_arg(self::PROBE_QUERY, $token, home_url('/')), ['timeout' => 10, 'sslverify' => false, 'redirection' => 0]);
        } finally {
            delete_transient(self::PROBE_TRANSIENT . $token);
            $this->files->delete($stored->key);
        }

        if ($response instanceof \WP_Error) {
            return new DeliveryCheck(false, \sprintf(
                /* translators: %s: the error */
                __('The site could not reach itself to test the method: %s', 'orillacart'),
                $response->get_error_message(),
            ), $rule);
        }
        if (wp_remote_retrieve_body($response) === self::PROBE_CONTENT) {
            return new DeliveryCheck(true, __('The web server sent the test file.', 'orillacart'), $rule);
        }

        return new DeliveryCheck(false, __('The web server did not send the test file. Add the setting below to its configuration, reload it, and try again.', 'orillacart'), $rule);
    }

    private function header(DeliverySettings $settings, string $key): ?string
    {
        $path = $this->files->path($key);
        if ($path === null) {
            return null;
        }

        return match ($settings->method) {
            DeliveryMethod::Php => null,
            DeliveryMethod::XSendfile => 'X-Sendfile: ' . $path,
            DeliveryMethod::XAccelRedirect => 'X-Accel-Redirect: ' . $settings->internalPrefix . $key,
        };
    }

    /** What the web server needs, for the folder the files are kept in. */
    private function rule(DeliverySettings $settings, string $key): string
    {
        $directory = \dirname((string) $this->files->path($key));

        return match ($settings->method) {
            DeliveryMethod::Php => '',
            DeliveryMethod::XSendfile => \sprintf("# Apache with mod_xsendfile (LiteSpeed needs no setting)\nXSendFile On\nXSendFilePath %s", $directory),
            DeliveryMethod::XAccelRedirect => \sprintf("location %s {\n    internal;\n    alias %s/;\n}", $settings->internalPrefix, $directory),
        };
    }

    /** A path such as /orillacart-files/: one slash at each end, letters, digits, dashes and slashes only. */
    private static function prefix(string $prefix): string
    {
        $prefix = trim((string) preg_replace('#[^A-Za-z0-9/_-]#', '', $prefix), '/');

        return $prefix === '' ? DeliverySettings::DEFAULT_PREFIX : '/' . $prefix . '/';
    }
}
