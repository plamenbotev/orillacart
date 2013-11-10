<?php
/* This loader is example how to create whole component as a separate plugin
 * to make it work you will need to add correct plugin meta comments here.
 */
/**
 * If the component is in wp plugins directory, load the main file on plugins_loaded event.
 * This will allow the framework to be fully loaded, before any attempt for execution.
 * 
 * Using create_function instead of closure, to ensure compatability with php lower than 5.3
 */
add_action('plugins_loaded', create_function('', 'require_once dirname(__FILE__)."/shop.php";'));

register_activation_hook(__FILE__, create_function('', 'require_once dirname(__FILE__)."/shop.php"; shop::on_activate();'));
register_deactivation_hook(__FILE__, create_function('', 'require_once dirname(__FILE__)."/shop.php"; shop::on_activate();'));