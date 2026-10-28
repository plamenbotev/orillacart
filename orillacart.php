<?php

declare(strict_types=1);

/**
 * Plugin Name:       OrillaCart
 * Description:       E-commerce for WordPress.
 * Version:           2.0.0-dev
 * Requires at least: 6.7
 * Requires PHP:      8.5
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       orillacart
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// WordPress refuses to activate the plugin below PHP 8.5; this covers a host downgraded afterwards.
if (PHP_VERSION_ID < 80500) {
    return;
}

// OrillaCart Network (network.php) may have defined it first, with the same version.
defined('ORILLACART_VERSION') || define('ORILLACART_VERSION', '2.0.0-dev');

// The release's libraries are renamed (PHP-Scoper): its loader also gives the polyfills' functions, such as mb_*,
// their global names for hosts without the extension. A development checkout has the plain one only.
require_once file_exists(__DIR__ . '/vendor/scoper-autoload.php') ? __DIR__ . '/vendor/scoper-autoload.php' : __DIR__ . '/vendor/autoload.php';

\OrillaCart\Platform\WordPress\Plugin::boot(__FILE__, ORILLACART_VERSION);
