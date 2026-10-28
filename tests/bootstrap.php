<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Integration tests run inside wp-env and load the WordPress install there; unit tests never load WordPress.
$wpLoad = getenv('ORILLACART_WP_LOAD');
if (is_string($wpLoad) && $wpLoad !== '') {
    // An add-on, as another plugin registers it: hooked before WordPress loads (as WordPress's own test suite
    // hooks its filters), since the shop reads the registry by wp_loaded to register the add-ons' product blocks.
    $GLOBALS['wp_filter'] = ['orillacart_extensions' => [10 => [['function' => OrillaCart\Tests\Fixtures\Extension\TestAddOn::register(...), 'accepted_args' => 1]]]];
    require $wpLoad;
}
