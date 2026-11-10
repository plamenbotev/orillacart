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
 * Nothing is evaluated unchecked: a file carries the site's signature of its build, its key and its source on its
 * last line, its source has to pass the GeneratedCodeGate, and the class it declares has to be the one asked for;
 * a file that fails any of these (planted, edited, moved from another key or build, from another site, or made by
 * a compiler with a bug) is deleted and the class compiled again.
 *
 * What runs is the bytes that were checked, evaluated from memory, never a file read or included again: a writer
 * of the cache folder who swaps a file after the check changes nothing, as no file is left to swap. The cost is
 * OPcache's: each request compiles the classes it uses afresh rather than from shared memory, which keeping the
 * check cannot avoid (anything OPcache would load is a file in a writable folder).
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
        $source = $file === false ? null : $this->unsigned($key, $file);
        if ($source === null || GeneratedCodeGate::refuse($source, $this->site()) !== null || self::declared($source) !== ltrim($className, '\\')) {
            @unlink($path);

            return false;
        }
        if (!class_exists($className, false)) {
            self::evaluate($source);
        }

        return class_exists($className, false);
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
            $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($temporary, $this->signed($key, $source)) === false || !rename($temporary, $path)) {
                @unlink($temporary);
            }
        }
        if (!class_exists($className, false)) {
            self::evaluate($source);
        }
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

    /** Defines the class of source that passed the gate: what the compiler made, from memory. */
    private static function evaluate(string $source): void
    {
        eval(substr($source, \strlen('<?php')));
    }

    /**
     * The source with the site's signature of it, its key and the build as a trailing comment, which PHP ignores
     * and load() checks: a signed file moved to another key or build is refused as an edited one is.
     */
    public function signed(string $key, string $source): string
    {
        return $source . self::TRAILER . $this->signatures->sign(self::PURPOSE, $this->signedWhat($key, $source)) . "\n";
    }

    /** The source a signed file of this key holds, or null when the signature is missing, not this site's, or of another key or build. */
    public function unsigned(string $key, string $file): ?string
    {
        $at = strrpos($file, self::TRAILER);
        if ($at === false) {
            return null;
        }
        $source = substr($file, 0, $at);
        $signature = rtrim(substr($file, $at + \strlen(self::TRAILER)), "\n");

        return $this->signatures->verify(self::PURPOSE, $this->signedWhat($key, $source), $signature) ? $source : null;
    }

    private function signedWhat(string $key, string $source): string
    {
        return $this->context->build . "\n" . $key . "\n" . $source;
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
