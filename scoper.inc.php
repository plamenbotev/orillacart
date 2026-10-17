<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

/*
 * Moves every bundled library under OrillaCart\Vendor so other plugins' copies of Symfony,
 * Doctrine or PSR packages cannot collide with ours. Run by bin/build from the staging copy.
 */

/** @return list<string> */
$wordpressExcludes = static function (string $kind): array {
    $file = __DIR__ . '/vendor/sniccowp/php-scoper-wordpress-excludes/generated/exclude-wordpress-' . $kind . '.json';
    $names = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter((array) $names, 'is_string'));
};

return [
    'prefix' => 'OrillaCart\\Vendor',

    'finders' => [
        Finder::create()->files()->in(['src', 'config', 'migrations', 'generated'])->name('*.php'),
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->in('vendor')
            ->exclude(['bin', 'doc', 'docs', 'test', 'tests', 'Tests'])
            ->notName('/\.(md|dist|neon|xml|yml|yaml|rst|txt)$|^(LICENSE|Makefile|composer\.(json|lock))$/'),
        Finder::create()->append(['orillacart.php', 'uninstall.php']),
    ],

    // Global files that reference only the plugin and WordPress: copied as they are, not wrapped in the prefix namespace.
    // Absolute paths: relative ones resolve against this config file, not the staging copy being scoped.
    'exclude-files' => array_map(
        static fn(string $file): string => getcwd() . '/' . $file,
        ['orillacart.php', 'uninstall.php', 'generated/container.php'],
    ),

    // The plugin's own code keeps its namespace; only libraries move under OrillaCart\Vendor.
    'exclude-namespaces' => ['/^OrillaCart(?!\\\\Vendor)(\\\\|$)/i'],

    // WordPress is global and shared with every other plugin, so it is never prefixed.
    'exclude-classes' => [
        ...$wordpressExcludes('classes'),
        ...$wordpressExcludes('interfaces'),
        ...$wordpressExcludes('traits'),
        'WP_CLI',
    ],
    'exclude-functions' => $wordpressExcludes('functions'),
    'exclude-constants' => [
        ...$wordpressExcludes('constants'),
        'ABSPATH', 'WPINC', 'WP_DEBUG', 'WP_CLI', 'WP_UNINSTALL_PLUGIN',
        'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_CHARSET', 'DB_COLLATE',
        'ORILLACART_VERSION',
    ],

    'patchers' => [
        // Libraries name classes and class constants in plain strings: defined('Doctrine\ORM\...::FETCH_' . $mode)
        // in Doctrine's mapping drivers, 'Doctrine\ORM\Query\TokenType::T_' in the DQL lexer, an interface name the
        // validator compares with. PHP-Scoper leaves strings with "::" or used as values alone, so entities and
        // DQL queries fail. Prefix every string literal that is nothing but a library class name, with an optional
        // "::CONSTANT" part; messages that mention a class contain spaces and stay as they are.
        static function (string $filePath, string $prefix, string $contents): string {
            if (str_contains($filePath, '/composer/')) {
                return $contents;
            }

            // Keep the string's own separator: single backslashes, or escaped ones inside double quotes.
            return (string) preg_replace_callback(
                "/(['\"])((?:Doctrine|Symfony|Psr|Twig|Nyholm|phpDocumentor|PHPStan)(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+(?:::[A-Za-z_]*)?)\\1/",
                static function (array $match) use ($prefix): string {
                    $separator = str_contains($match[2], '\\\\') ? '\\\\' : '\\';

                    return $match[1] . str_replace('\\', $separator, $prefix) . $separator . $match[2] . $match[1];
                },
                $contents,
            );
        },
        // Libraries probe optional PHP extensions with function_exists('iconv'); PHP-Scoper prefixes those names,
        // so the probe always fails. Put the global names back.
        static function (string $filePath, string $prefix, string $contents): string {
            $functions = ['iconv', 'gzdeflate', 'shell_exec', 'proc_open', 'pcntl_signal', 'imagecreatefromstring', 'token_get_all'];
            $prefixes = preg_quote($prefix, '/') . '|' . preg_quote(str_replace('\\', '\\\\', $prefix), '/');

            return (string) preg_replace(
                '/([\'"])(?:' . $prefixes . ')\\\\{1,2}(' . implode('|', $functions) . ')\1/',
                '$1$2$1',
                $contents,
            );
        },
        // Regex replacement strings such as 'e\1es' or '_\\0' look like namespaced names to PHP-Scoper, which
        // prefixes them. A class name never holds a backslash followed by a digit, so any prefixed literal whose
        // original had one is put back as it was.
        static function (string $filePath, string $prefix, string $contents): string {
            // Only files where a prefixed literal goes on with a lower-case letter or "_", which no class name does.
            $suspect = '/[\'"]' . preg_quote($prefix, '/') . '\\\\{1,2}[a-z_]/';
            $suspectEscaped = '/[\'"]' . preg_quote(str_replace('\\', '\\\\', $prefix), '/') . '\\\\{1,2}[a-z_]/';
            if (!is_file($filePath) || (preg_match($suspect, $contents) !== 1 && preg_match($suspectEscaped, $contents) !== 1)) {
                return $contents;
            }
            $original = (string) file_get_contents($filePath);
            if (preg_match('/[\'"][^\'"]*\\\\[0-9]/', $original) !== 1) {
                return $contents;
            }

            $literals = static fn(string $code): array => array_values(array_filter(
                token_get_all($code),
                static fn(mixed $token): bool => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING,
            ));
            $before = $literals($original);
            $after = $literals($contents);
            if (count($before) !== count($after)) {
                throw new RuntimeException("Cannot match the string literals of {$filePath} before and after prefixing.");
            }

            $restore = [];
            foreach ($after as $index => $token) {
                $prefixed = str_contains($token[1], $prefix . '\\') || str_contains($token[1], str_replace('\\', '\\\\', $prefix));
                if ($prefixed && preg_match('/\\\\[0-9]/', $before[$index][1]) === 1) {
                    $restore[$token[2]][$token[1]] = $before[$index][1];
                }
            }
            if ($restore === []) {
                return $contents;
            }

            $lines = explode("\n", $contents);
            foreach ($restore as $line => $replacements) {
                $lines[$line - 1] = strtr($lines[$line - 1], $replacements);
            }

            return implode("\n", $lines);
        },
        // Twig writes PHP for each template with its own class names in strings ("use Twig\\Environment;",
        // '\Twig\Extension\CoreExtension::captureOutput(' for macros), which PHP-Scoper does not rewrite; point
        // them at the prefixed classes. Code never names a class that way outside a string literal, so every
        // Twig source file is safe to rewrite.
        static function (string $filePath, string $prefix, string $contents): string {
            if (!str_contains($filePath, 'twig/twig/src/')) {
                return $contents;
            }
            // Escaped separators, in double quotes and as PHP-Scoper prints some single-quoted ones: Twig\\Environment.
            $escapedPrefix = str_replace('\\', '\\\\', $prefix) . '\\\\';
            $contents = (string) preg_replace_callback(
                '/(?<![A-Za-z])(?<!Vendor\\\\\\\\)Twig\\\\\\\\/',
                static fn(array $match): string => $escapedPrefix . $match[0],
                $contents,
            );

            // A single separator right after the opening quote: '\Twig\Extension\CoreExtension::captureOutput('.
            return (string) preg_replace_callback(
                '/(?<=[\'"])\\\\Twig\\\\(?=[A-Z])/',
                static fn(array $match): string => '\\' . $prefix . $match[0],
                $contents,
            );
        },
    ],

    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
