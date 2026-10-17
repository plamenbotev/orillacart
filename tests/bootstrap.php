<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Integration tests run inside wp-env and load the WordPress install there; unit tests never load WordPress.
$wpLoad = getenv('ORILLACART_WP_LOAD');
if (is_string($wpLoad) && $wpLoad !== '') {
    require $wpLoad;
}
