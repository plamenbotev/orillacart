<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Scripting;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Scripting\Application\GeneratedCodeGate;
use OrillaCart\Scripting\Application\ScriptStore;
use OrillaCart\Shared\Security\Signatures;

/**
 * Compiled classes as PHP files under the shop's cache folder (`scripts/<build>/<key>.php`), which OPcache keeps
 * in memory; a new build gets a new folder and the old one is cleared, as Twig's is. Without a writable folder
 * the source is evaluated in memory, once per request.
 *
 * Each site's classes live in their own namespace (`…\Scripts\Site<id>`) and under their own keys, so two sites
 * of a network with the same extension never share a file or a class.
 * Nothing is included or evaluated unchecked: a file carries the site's signature of its source on its last
 * line, and the source has to pass the GeneratedCodeGate; a file that fails either (planted, edited, from
 * another site, or made by a compiler with a bug) is deleted and the class compiled again.
 *
 * What is included is the bytes that were checked, never the file read again: they are written to a new file
 * of a random name (created exclusively, 0600) in the same folder, included, and that file removed, so a writer
 * of the cache folder who swaps the file between the check and the include changes nothing. The cost is
 * OPcache's: each include compiles the class afresh rather than from shared memory, and the removed file's
 * entry is invalidated. Comparing the file's inode and times before and after the include was the other way,
 * kept by OPcache; it finds a swap only after the swapped code ran.
 */
final readonly class CompiledScriptStore implements ScriptStore
{
    private const string PURPOSE = 'compiled-script';
    private const string TRAILER = "\n// signed ";

    public function __construct(private PluginContext $context, private Signatures $signatures) {}

    public function site(): string
    {
        return \function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '1';
    }

    public function load(string $key, string $className): bool
    {
        $path = $this->path($key);
        if ($path === null || !is_file($path)) {
            return false;
        }
        $file = file_get_contents($path);
        $source = $file === false ? null : $this->unsigned($file);
        if ($file === false || $source === null || GeneratedCodeGate::refuse($source, $this->site()) !== null) {
            @unlink($path);

            return false;
        }

        // Its class is named for its source's checksum: defined already, it is this same code, included once.
        $declared = self::declared($source);
        if ($declared !== null && class_exists($declared, false)) {
            return class_exists($className, false);
        }

        return self::includeChecked(\dirname($path), $file) && class_exists($className, false);
    }

    /** The fully qualified class a compiled file declares; null when it declares none. */
    private static function declared(string $source): ?string
    {
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $source, $namespace) !== 1 || preg_match('/^(?:final\s+)?class\s+([A-Za-z0-9_]+)/m', $source, $class) !== 1) {
            return null;
        }

        return $namespace[1] . '\\' . $class[1];
    }

    public function put(string $key, string $className, string $source): void
    {
        $refused = GeneratedCodeGate::refuse($source, $this->site());
        if ($refused !== null) {
            throw new \InvalidArgumentException('The compiled code was refused: ' . $refused);
        }
        $path = $this->path($key);
        if ($path !== null) {
            $signed = $this->signed($source);
            $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($temporary, $signed) !== false && rename($temporary, $path) && (class_exists($className, false) || self::includeChecked(\dirname($path), $signed)) && class_exists($className, false)) {
                return;
            }
            @unlink($temporary);
        }
        // The class is defined in memory; the generated code is what the compiler made and the gate passed.
        eval(substr($source, \strlen('<?php')));
    }

    public function forget(string $keyPrefix): void
    {
        $folder = CacheFolders::path('scripts', $this->context->build);
        if ($folder === null) {
            return;
        }
        $files = glob($folder . '/' . self::safe($keyPrefix) . '*.php');
        foreach ($files === false ? [] : $files as $file) {
            @unlink($file);
        }
    }

    /**
     * Includes these bytes, already checked: from a file made for this include alone, under a random name no one
     * knew before, removed afterwards. False when the folder takes no such file.
     */
    public static function includeChecked(string $folder, string $bytes): bool
    {
        $temporary = $folder . '/.include-' . bin2hex(random_bytes(16)) . '.php';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            return false;
        }
        try {
            @chmod($temporary, 0o600);
            $written = fwrite($handle, $bytes) === \strlen($bytes);
            fclose($handle);
            if (!$written) {
                return false;
            }
            require $temporary;
        } finally {
            @unlink($temporary);
            if (\function_exists('opcache_invalidate')) {
                @opcache_invalidate($temporary, true);
            }
        }

        return true;
    }

    /** The source with the site's signature of it as a trailing comment, which PHP ignores and load() checks. */
    public function signed(string $source): string
    {
        return $source . self::TRAILER . $this->signatures->sign(self::PURPOSE, $source) . "\n";
    }

    /** The source a signed file holds, or null when the signature is missing or not this site's. */
    public function unsigned(string $file): ?string
    {
        $at = strrpos($file, self::TRAILER);
        if ($at === false) {
            return null;
        }
        $source = substr($file, 0, $at);
        $signature = rtrim(substr($file, $at + \strlen(self::TRAILER)), "\n");

        return $this->signatures->verify(self::PURPOSE, $source, $signature) ? $source : null;
    }

    private function path(string $key): ?string
    {
        $folder = CacheFolders::path('scripts', $this->context->build);

        return $folder === null ? null : $folder . '/' . self::safe($key) . '.php';
    }

    private static function safe(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $key) ?? $key;
    }
}
