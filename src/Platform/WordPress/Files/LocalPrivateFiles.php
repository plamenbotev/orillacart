<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Files;

use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Files\StorageCheck;
use OrillaCart\Shared\Files\StoredFile;
use OrillaCart\Shared\Lifecycle\LifecycleListener;

/**
 * Keeps private files on the server's disk, where no web server can reach them when possible:
 *
 * 1. the folder named by the ORILLACART_DOWNLOADS_DIR constant, if defined;
 * 2. otherwise orillacart-downloads next to the WordPress folder, outside the site, when that can be written;
 * 3. otherwise a folder with a random 64-character name in the uploads folder. Deny rules there stop Apache and
 *    IIS; Nginx ignores them, so the folder's name is secret and a self-check fetches a test file over HTTP: if the
 *    server hands it out, the admin is told and shown the rule to add.
 *
 * The folder is chosen when the first file is stored, where the process storing it can write, and remembered:
 * a folder made by WP-CLI running as root would be one the web server cannot write to.
 *
 * Every file gets a random name without an extension, so nothing in the folder runs or is sniffed as a page.
 */
final readonly class LocalPrivateFiles implements PrivateFiles, LifecycleListener
{
    public const string SECRET_OPTION = 'orillacart_downloads_folder';
    /** The folder chosen when the first file was stored, so every process uses the same one. */
    public const string DIRECTORY_OPTION = 'orillacart_downloads_directory';
    private const string CHECK_TRANSIENT = 'orillacart_downloads_check';
    private const string PROBE = 'orillacart-probe.txt';
    private const string PROBE_CONTENT = 'orillacart private file probe';

    public function store(string $uploadedPath, string $filename): StoredFile
    {
        $directory = $this->directory();
        $key = bin2hex(random_bytes(16));
        $target = $directory . '/' . $key;
        $moved = is_uploaded_file($uploadedPath) ? move_uploaded_file($uploadedPath, $target) : rename($uploadedPath, $target);
        if (!$moved) {
            throw new \RuntimeException(\sprintf('The file could not be saved in %s.', $directory));
        }
        chmod($target, 0o640);
        $type = wp_check_filetype($filename);

        return new StoredFile($key, sanitize_file_name($filename), \is_string($type['type']) ? $type['type'] : 'application/octet-stream', (int) filesize($target));
    }

    public function path(string $key): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
            return null;
        }
        $path = $this->directory() . '/' . $key;

        return is_file($path) ? $path : null;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if ($path !== null) {
            wp_delete_file($path);
        }
    }

    public function check(bool $fresh = false): StorageCheck
    {
        $directory = $this->directory();
        $location = $this->location();
        $rule = \sprintf("location ^~ /%s/ {\n    deny all;\n}", trim((string) wp_make_link_relative($this->uploadsUrl($directory) ?? ''), '/'));
        $url = $this->uploadsUrl($directory);
        if ($url === null) {
            return new StorageCheck($location, $directory, null, '');
        }

        $cached = get_transient(self::CHECK_TRANSIENT);
        if (!$fresh && \is_array($cached) && \is_bool($cached['reachable'] ?? null)) {
            return new StorageCheck($location, $directory, $cached['reachable'], $rule);
        }
        file_put_contents($directory . '/' . self::PROBE, self::PROBE_CONTENT);
        $response = wp_remote_get($url . '/' . self::PROBE, ['timeout' => 5, 'sslverify' => false, 'redirection' => 0]);
        wp_delete_file($directory . '/' . self::PROBE);
        if ($response instanceof \WP_Error) {
            return new StorageCheck($location, $directory, null, $rule);
        }
        $reachable = wp_remote_retrieve_response_code($response) === 200 && str_contains(wp_remote_retrieve_body($response), self::PROBE_CONTENT);
        set_transient(self::CHECK_TRANSIENT, ['reachable' => $reachable], DAY_IN_SECONDS);

        return new StorageCheck($location, $directory, $reachable, $rule);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        $directory = $this->directory(create: false);
        if (is_dir($directory)) {
            $files = scandir($directory);
            foreach ($files === false ? [] : $files as $file) {
                if ($file !== '.' && $file !== '..') {
                    wp_delete_file($directory . '/' . $file);
                }
            }
            rmdir($directory);
        }
        delete_option(self::SECRET_OPTION);
        delete_option(self::DIRECTORY_OPTION);
        delete_transient(self::CHECK_TRANSIENT);
    }

    private function location(): string
    {
        if (\defined('ORILLACART_DOWNLOADS_DIR')) {
            return StorageCheck::CONFIGURED;
        }

        return str_starts_with($this->directory(create: false), wp_upload_dir(null, false)['basedir'] . '/') ? StorageCheck::UPLOADS : StorageCheck::OUTSIDE_WEB_ROOT;
    }

    private function directory(bool $create = true): string
    {
        if (\defined('ORILLACART_DOWNLOADS_DIR')) {
            $directory = rtrim(self::configured(), '/');
        } else {
            $chosen = get_option(self::DIRECTORY_OPTION);
            $directory = \is_string($chosen) && $chosen !== '' ? $chosen : ($this->outsideWebRoot() ?? $this->uploadsDirectory());
            if ($create && $chosen !== $directory) {
                update_option(self::DIRECTORY_OPTION, $directory, false);
            }
        }
        if ($create && !is_dir($directory)) {
            if (!wp_mkdir_p($directory)) {
                throw new \RuntimeException(\sprintf('The downloads folder %s could not be made.', $directory));
            }
            // For servers that read them: Apache and IIS refuse every request into the folder.
            file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
            file_put_contents($directory . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
            file_put_contents($directory . '/index.php', "<?php\n// Silence.\n");
        }

        return $directory;
    }

    private static function configured(): string
    {
        $configured = \constant('ORILLACART_DOWNLOADS_DIR');

        return \is_string($configured) ? $configured : throw new \LogicException('ORILLACART_DOWNLOADS_DIR is not a folder path.');
    }

    /** The folder next to WordPress's, when this process can write there. */
    private function outsideWebRoot(): ?string
    {
        $parent = \dirname(rtrim(ABSPATH, '/'));
        $directory = $parent . '/orillacart-downloads';

        return is_dir($directory) ? (wp_is_writable($directory) ? $directory : null) : (wp_is_writable($parent) ? $directory : null);
    }

    private function uploadsDirectory(): string
    {
        $secret = get_option(self::SECRET_OPTION);
        if (!\is_string($secret) || preg_match('/^[a-f0-9]{64}$/', $secret) !== 1) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::SECRET_OPTION, $secret, false);
        }

        return wp_upload_dir(null, false)['basedir'] . '/orillacart-' . $secret;
    }

    /** The folder's address on the web, for a folder inside the uploads folder; null for one outside it. */
    private function uploadsUrl(string $directory): ?string
    {
        $uploads = wp_upload_dir(null, false);

        return str_starts_with($directory, $uploads['basedir'] . '/') ? $uploads['baseurl'] . substr($directory, \strlen($uploads['basedir'])) : null;
    }
}
