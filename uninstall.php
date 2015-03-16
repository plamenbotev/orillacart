<?php

defined('ABSPATH') or die();
if (!defined('WP_UNINSTALL_PLUGIN'))
    exit();

define('_VALID_EXEC', 1);
define('DS', "/");

//include the autoloader
require_once 'autoload.php';
//commonly used functions
require_once dirname(__FILE__) . "/core/functions.php";
require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
//session::initialize();
//include the default built in component
if (file_exists(realpath(dirname(__FILE__)) . "/com_shop/shop.php") && !is_plugin_active("com_shop/loader.php")) {
    require_once realpath(dirname(__FILE__)) . "/com_shop/shop.php";
    $Application = Factory::getApplication();
    Factory::getComponent('shop')->init();
    shop::uninstall();
}







