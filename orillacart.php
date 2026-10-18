<?php

declare(strict_types=1);

/**
 * Plugin Name:       OrillaCart
 * Description:       E-commerce for WordPress.
 * Version:           2.0.0-dev
 * Requires at least: 6.7
 * Requires PHP:      8.4
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       orillacart
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// WordPress refuses to activate the plugin below PHP 8.4; this covers a host downgraded afterwards.
if (PHP_VERSION_ID < 80400) {
    return;
}

define('ORILLACART_VERSION', '2.0.0-dev');

require __DIR__ . '/vendor/autoload.php';

\OrillaCart\Platform\WordPress\Plugin::boot(__FILE__, ORILLACART_VERSION);
