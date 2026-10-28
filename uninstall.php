<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if (PHP_VERSION_ID < 80500) {
    return;
}

// The release's libraries are renamed (PHP-Scoper): its loader also gives the polyfills' functions, such as mb_*,
// their global names for hosts without the extension. A development checkout has the plain one only.
require file_exists(__DIR__ . '/vendor/scoper-autoload.php') ? __DIR__ . '/vendor/scoper-autoload.php' : __DIR__ . '/vendor/autoload.php';

\OrillaCart\Platform\WordPress\Plugin::uninstall(__DIR__ . '/orillacart.php');
