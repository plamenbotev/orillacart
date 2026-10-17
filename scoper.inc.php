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

    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
