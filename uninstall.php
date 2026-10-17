<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

if (PHP_VERSION_ID < 80400) {
    return;
}

require __DIR__ . '/vendor/autoload.php';

\OrillaCart\Platform\WordPress\Plugin::uninstall(__DIR__ . '/orillacart.php');
