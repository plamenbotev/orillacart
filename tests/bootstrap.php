<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Integration tests run inside wp-env and load the WordPress install there; unit tests never load WordPress.
$wpLoad = getenv('ORILLACART_WP_LOAD');
if (is_string($wpLoad) && $wpLoad !== '') {
    require $wpLoad;
    // An add-on, as another plugin registers it; the shop reads the registry the first time it needs it.
    add_action('orillacart_extensions', OrillaCart\Tests\Fixtures\Extension\TestAddOn::register(...));
}
