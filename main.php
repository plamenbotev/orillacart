<?php

/*
  Plugin Name: OrillaCart
  Version: 1.4.4
  Description: ecommerce solution for WordPress
  Plugin URI: http://orillacart.com
  Author: orillacart.com team
  Author URI: http://orillacart.com
 */

defined('ABSPATH') or die();


define('_VALID_EXEC', 1);
defined("DS") or define('DS', "/");
define("ORILLA_FRAMEWORK_BASE", realpath(dirname(__FILE__)));
define("ORILLA_FRAMEWORK_CORE", realpath(dirname(__FILE__) . DS . "core"));
define("ORILLACART_VERSION", "1.4.4");

define("ORILLA_FRAMEWORK_PUBLIC_KEY", realpath(dirname(__FILE__) . DS . "core" . DS . "public_key" . DS . "orillacart.pub"));

$prev = set_exception_handler('unhandledExceptions');


//include the autoloader
require_once 'autoload.php';

//commonly used functions
require_once dirname(__FILE__) . "/core/functions.php";

require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

//session::initialize();
//include the default built in component
if (file_exists(realpath(dirname(__FILE__)) . "/com_shop/shop.php") && !is_plugin_active("com_shop/loader.php")) {
    require_once realpath(dirname(__FILE__)) . "/com_shop/shop.php";
}

//Load the application
$Application = Factory::getApplication();

//ensures that all components are included in priority 10 or lower before loading them at priority 11
add_action('plugins_loaded', array($Application, 'load_active_components'), 11);

//install uninstall handlers
if (file_exists(realpath(dirname(__FILE__)) . "/com_shop/shop.php") && !is_plugin_active("com_shop/loader.php")) {
    register_activation_hook(__FILE__, 'shop::on_activate');
    register_deactivation_hook(__FILE__, 'shop::on_deactivate');
}

//Initialize the application.
add_action('init', array($Application, 'initialize'));






if (!is_null($prev)) {
    set_exception_handler($prev);
}

function unhandledExceptions($e) {
    @ob_end_clean();
    do_action("before_exception_print", $e);
    wp_die("Uncaught exception: <pre>" . $e->getMessage() . "</pre>");
    do_action("after_exception_print", $e);
    exit;
}
