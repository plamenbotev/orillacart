<?php

defined('ABSPATH') or die();
if (!defined('WP_UNINSTALL_PLUGIN'))
    exit();

require_once dirname(__FILE__) . "/shop.php";
shop::uninstall();
