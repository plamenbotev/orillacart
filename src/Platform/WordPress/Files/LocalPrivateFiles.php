<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Files;

use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Shared\Files\StorageCheck;
use OrillaCart\Shared\Files\StoredFile;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Keeps private files on the server's disk, where no web server can reach them when possible:
 *
 * 1. the folder named by the ORILLACART_DOWNLOADS_DIR constant, if defined (in a network, a folder per site in it);
 * 2. otherwise a folder of this install and site in orillacart-downloads next to the WordPress folder, outside the
 *    site, when that can be written: installs that share the parent folder (staging beside live, a host's add-on
 *    domains) and the sites of a network never share one, so removing one shop's data never touches another's;
 * 3. otherwise a folder with a random 64-character name in the uploads folder. Deny rules there stop Apache and
 *    IIS; Nginx ignores them, so the folder's name is secret and a self-check fetches a test file over HTTP: if the
 *    server hands it out, the admin is told and shown the rule to add.
 *
 * The folder is chosen when the first file is stored, where the process storing it can write, and remembered:
 * a folder made by WP-CLI running as root would be one the web server cannot write to. A marker in it names the
 * site and install it is for. The folder remembered is used only when it is this site's (the marker says so, or,
 * unmarked, it is where this site would make it). When it is not, or it is gone, the site was moved, restored or
 * copied: the shop keeps the folder it remembers, serves, stores and deletes nothing, and the Status screen asks
 * the shop manager (answer()): moved, adopt the folder; a copy, start a new one. Picking a new folder unasked
 * would leave a moved shop's customers with links to files it no longer finds, and forget where they are.
 *
 * Every file gets a random name without an extension, so nothing in the folder runs or is sniffed as a page.
 */
final readonly class LocalPrivateFiles implements PrivateFiles, LifecycleListener, RemovesShopData
{
    public const string SECRET_OPTION = 'orillacart_downloads_folder';
    /** The folder chosen when the first file was stored, so every process uses the same one. */
    public const string DIRECTORY_OPTION = 'orillacart_downloads_directory';
    /** The folder the plugin made itself, which it may remove; one it found (ORILLACART_DOWNLOADS_DIR) stays. */
    public const string CREATED_OPTION = 'orillacart_downloads_created';
    /** What the plugin writes into the folder besides the files: the web servers' deny rules. */
    private const array DENY_FILES = ['.htaccess', 'web.config', 'index.php'];
    /** Names the site and install the folder is for. */
    public const string MARKER = '.orillacart-site';
    private const string CHECK_TRANSIENT = 'orillacart_downloads_check';
    private const string PROBE = 'orillacart-probe.txt';
    private const string PROBE_CONTENT = 'orillacart private file probe';

    public function store(string $uploadedPath, string $filename): StoredFile
    {
        if ($this->unclaimed() !== null) {
            throw new \RuntimeException('The downloads folder is not known as this site\'s: answer on the shop\'s Status screen whether the site moved or is a copy.');
        }
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
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1 || $this->unclaimed() !== null) {
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
        $unclaimed = $this->unclaimed();
        if ($unclaimed !== null) {
            return new StorageCheck($this->location(), $unclaimed[1], null, '', $unclaimed[0]);
        }
        $directory = $this->directory();
        // Written again on every check: a deploy or a backup restore may have lost them.
        self::writeDenyFiles($directory);
        $location = $this->location();
        $url = $this->webUrl($directory);
        $rule = \sprintf("location ^~ /%s/ {\n    deny all;\n}", trim((string) wp_make_link_relative($url ?? ''), '/'));
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

    public function answer(string $answer): StorageCheck
    {
        $unclaimed = $this->unclaimed();
        if ($unclaimed === null) {
            return $this->check(true);
        }
        $created = get_option(self::CREATED_OPTION) === get_option(self::DIRECTORY_OPTION);
        if ($answer === StorageCheck::ADOPT) {
            if ($unclaimed[0] === StorageCheck::MISSING) {
                throw new \DomainException('The folder is gone, so there is nothing to adopt: start a new one.');
            }
            $folder = $unclaimed[1];
            self::mark($folder);
            update_option(self::DIRECTORY_OPTION, $folder, false);
            if ($created) {
                update_option(self::CREATED_OPTION, $folder, false);
            }
            if (preg_match('/^orillacart-([a-f0-9]{64})$/', basename($folder), $secret) === 1) {
                update_option(self::SECRET_OPTION, $secret[1], false);
            }
        } elseif ($answer === StorageCheck::START_NEW) {
            delete_option(self::DIRECTORY_OPTION);
            if ($created) {
                delete_option(self::CREATED_OPTION);
            }
            if ($this->unclaimed() !== null) {
                throw new \LogicException('The downloads folder is still not this site\'s.');
            }
        } else {
            throw new \DomainException('Answer adopt or new.');
        }
        delete_transient(self::CHECK_TRANSIENT);

        return $this->check(true);
    }

    /**
     * Why the folder remembered is not used: [StorageCheck::ELSEWHERE or MISSING, the folder found (where it was
     * moved to, for a moved site) or remembered]; null when it is this site's or none is remembered yet.
     *
     * @return array{string, string}|null
     */
    private function unclaimed(): ?array
    {
        if (\defined('ORILLACART_DOWNLOADS_DIR')) {
            return null;
        }
        $chosen = get_option(self::DIRECTORY_OPTION);
        if (!\is_string($chosen) || $chosen === '') {
            return null;
        }
        $found = is_dir($chosen) ? $chosen : $this->movedTo($chosen);
        $marker = $found === null ? false : @file_get_contents($found . '/' . self::MARKER);

        return self::claim($chosen, $found, \is_string($marker) ? $marker : null, self::marker(), $found !== null && $this->madeHere($found));
    }

    /**
     * Whose the remembered folder is.
     *
     * @param string      $chosen   the folder remembered
     * @param string|null $found    that folder, or where it is now when the site moved; null when it is nowhere
     * @param string|null $marker   the marker in the folder found; null for none
     * @param string      $ours     this site's marker
     * @param bool        $madeHere whether the folder found is where this site makes one, for one without a marker
     *
     * @return array{string, string}|null null when the folder is this site's
     */
    public static function claim(string $chosen, ?string $found, ?string $marker, string $ours, bool $madeHere): ?array
    {
        if ($found === null) {
            return [StorageCheck::MISSING, $chosen];
        }
        if ($found === $chosen && ($marker === null ? $madeHere : $marker === $ours)) {
            return null;
        }

        return [StorageCheck::ELSEWHERE, $found];
    }

    /**
     * Where a folder that is gone from where it was remembered is, when the site moved with it: the same folder
     * in this install's uploads folder, or in the orillacart-downloads folder next to this WordPress.
     */
    private function movedTo(string $chosen): ?string
    {
        $name = basename($chosen);
        $candidates = preg_match('/^orillacart-[a-f0-9]{64}$/', $name) === 1
            ? [wp_upload_dir(null, false)['basedir'] . '/' . $name]
            : [\dirname(rtrim(ABSPATH, '/')) . '/orillacart-downloads/' . $name];
        foreach ($candidates as $candidate) {
            if ($candidate !== $chosen && is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    /**
     * Removes the files the plugin stored and its deny rules, never anything else: ORILLACART_DOWNLOADS_DIR may
     * name a folder others use too. The folder itself goes only when the plugin made it and it is empty now.
     */
    public function uninstall(): void
    {
        $directory = $this->directory(create: false);
        if (is_dir($directory) && $this->ours($directory)) {
            $files = scandir($directory);
            foreach ($files === false ? [] : $files as $file) {
                if (preg_match('/^[a-f0-9]{32}$/', $file) === 1 || \in_array($file, [...self::DENY_FILES, self::PROBE, self::MARKER], true)) {
                    wp_delete_file($directory . '/' . $file);
                }
            }
            $left = scandir($directory);
            if (get_option(self::CREATED_OPTION) === $directory && $left !== false && array_diff($left, ['.', '..']) === []) {
                rmdir($directory);
                // The folder the installs and sites share goes too once the last of them left it.
                $shared = \dirname($directory);
                $others = basename($shared) === 'orillacart-downloads' ? scandir($shared) : false;
                if ($others !== false && array_diff($others, ['.', '..']) === []) {
                    rmdir($shared);
                }
            }
        }
        delete_option(self::SECRET_OPTION);
        delete_option(self::DIRECTORY_OPTION);
        delete_option(self::CREATED_OPTION);
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
            $directory = rtrim(self::configured(), '/') . (is_multisite() ? '/site-' . get_current_blog_id() : '');
        } else {
            $chosen = get_option(self::DIRECTORY_OPTION);
            if (\is_string($chosen) && $chosen !== '' && $this->unclaimed() !== null) {
                // Kept, and neither made nor marked, until the shop manager says whose it is (answer()).
                return $chosen;
            }
            $directory = \is_string($chosen) && $chosen !== '' ? $chosen : ($this->outsideWebRoot() ?? $this->uploadsDirectory());
            if ($create && $chosen !== $directory) {
                update_option(self::DIRECTORY_OPTION, $directory, false);
            }
        }
        if ($create && !is_dir($directory)) {
            if (!wp_mkdir_p($directory)) {
                throw new \RuntimeException(\sprintf('The downloads folder %s could not be made.', $directory));
            }
            update_option(self::CREATED_OPTION, $directory, false);
            self::writeDenyFiles($directory);
        }
        if ($create && self::marked($directory) === null && wp_is_writable($directory)) {
            self::mark($directory);
        }

        return $directory;
    }

    /** Writes the marker that says the folder is this site's, of this install. */
    public static function mark(string $directory): void
    {
        file_put_contents($directory . '/' . self::MARKER, self::marker());
    }

    private static function marker(): string
    {
        return 'site ' . get_current_blog_id() . ' of ' . substr(hash('sha256', ABSPATH), 0, 16) . "\n";
    }

    /** Whether the folder's marker is this site's; null when it has none. */
    private static function marked(string $directory): ?bool
    {
        $file = $directory . '/' . self::MARKER;
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === self::marker();
    }

    /**
     * Whether the folder is this site's: its marker says so, or, unmarked (made before markers), it is the folder
     * named in wp-config.php or where this site makes one.
     */
    private function ours(string $directory): bool
    {
        $marked = self::marked($directory);
        if ($marked !== null) {
            return $marked;
        }
        if (\defined('ORILLACART_DOWNLOADS_DIR')) {
            return true;
        }

        return $this->madeHere($directory);
    }

    /** Whether the folder is where this site makes one, outside the site or in its uploads folder. */
    private function madeHere(string $directory): bool
    {
        return $directory === self::siteFolder(\dirname(rtrim(ABSPATH, '/')) . '/orillacart-downloads', ABSPATH, get_current_blog_id())
            || preg_match('#^' . preg_quote(wp_upload_dir(null, false)['basedir'], '#') . '/orillacart-[a-f0-9]{64}$#', $directory) === 1;
    }

    /** For servers that read them: Apache and IIS refuse every request into the folder. */
    private static function writeDenyFiles(string $directory): void
    {
        if (!is_dir($directory) || !wp_is_writable($directory)) {
            return;
        }
        file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($directory . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        file_put_contents($directory . '/index.php', "<?php\n// Silence.\n");
    }

    private static function configured(): string
    {
        $configured = \constant('ORILLACART_DOWNLOADS_DIR');

        return \is_string($configured) ? $configured : throw new \LogicException('ORILLACART_DOWNLOADS_DIR is not a folder path.');
    }

    /**
     * This install's and site's folder in the one next to WordPress's, when this process can write there: named by
     * the install's own folder (ABSPATH) and the site's id.
     */
    private function outsideWebRoot(): ?string
    {
        $parent = \dirname(rtrim(ABSPATH, '/'));
        $shared = $parent . '/orillacart-downloads';
        $directory = self::siteFolder($shared, ABSPATH, get_current_blog_id());
        $writable = static fn(string $folder): ?bool => is_dir($folder) ? wp_is_writable($folder) : null;

        return ($writable($directory) ?? $writable($shared) ?? wp_is_writable($parent)) ? $directory : null;
    }

    /** The folder of one install (by its WordPress folder) and one of its sites, in the folder they share. */
    public static function siteFolder(string $shared, string $wordpressFolder, int $siteId): string
    {
        return $shared . '/' . substr(hash('sha256', $wordpressFolder), 0, 16) . '-site-' . $siteId;
    }

    private function uploadsDirectory(): string
    {
        $secret = get_option(self::SECRET_OPTION);
        $base = wp_upload_dir(null, false)['basedir'] . '/orillacart-';
        if (!\is_string($secret) || preg_match('/^[a-f0-9]{64}$/', $secret) !== 1 || self::marked($base . $secret) === false) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::SECRET_OPTION, $secret, false);
        }

        return $base . $secret;
    }

    /**
     * The folder's address on the web, for a folder a web server may hand out: inside the uploads folder or
     * anywhere under the site's own folder; null for one outside it.
     */
    private function webUrl(string $directory): ?string
    {
        $uploads = wp_upload_dir(null, false);
        if (str_starts_with($directory, $uploads['basedir'] . '/')) {
            return $uploads['baseurl'] . substr($directory, \strlen($uploads['basedir']));
        }
        $root = rtrim(ABSPATH, '/');

        return str_starts_with($directory, $root . '/') ? site_url(substr($directory, \strlen($root))) : null;
    }
}
