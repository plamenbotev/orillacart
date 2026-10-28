<?php

declare(strict_types=1);

/**
 * Plugin Name:       OrillaCart Network
 * Description:       Brings a network's OrillaCart shops up to date after an update and shows how it goes in the network admin, also when the main site does not run the shop. Not needed when OrillaCart is on for the whole network.
 * Version:           2.0.0-dev
 * Requires at least: 6.7
 * Requires PHP:      8.4
 * Network:           true
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       orillacart
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

if (PHP_VERSION_ID < 80400 || !is_multisite()) {
    return;
}

// The same version as orillacart.php; a test keeps them together.
defined('ORILLACART_VERSION') || define('ORILLACART_VERSION', '2.0.0-dev');

require_once file_exists(__DIR__ . '/vendor/scoper-autoload.php') ? __DIR__ . '/vendor/scoper-autoload.php' : __DIR__ . '/vendor/autoload.php';

// Only the network's side of updates: the shop itself does not run on the main site for it.
\OrillaCart\Platform\WordPress\Upgrades\NetworkControl::boot(__DIR__ . '/orillacart.php', ORILLACART_VERSION);
