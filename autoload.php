<?php

defined('_VALID_EXEC') or die('access denied');


spl_autoload_register('autoload');

/**
  Auto loading the core classes and helpers
 * */
function autoload($class) {

    static $paths = array();
    
    do_action("override_core_autoload");
	
    if(class_exists($class)) return true;
	
    $paths = (array) array_unique(array_merge((array) $paths, (array) apply_filters('register_autoload_path', $paths,$class)));
	
    $paths = array_map('dirname', $paths);

    foreach ((array) $paths as $path) {
	
        if (file_exists($path . DS . strtolower($class) . ".php")) {

            require_once($path . DS . strtolower($class) . ".php");
			
            return true;
        }
    }

    if (file_exists(dirname(__FILE__) . "/core/" . strtolower($class) . ".php")) {

        require_once(dirname(__FILE__) . "/core/" . strtolower($class) . ".php");
		
    } else if (file_exists(dirname(__FILE__) . "/helpers/" . strtolower($class) . ".php")) {

        require_once(dirname(__FILE__) . "/helpers/" . strtolower($class) . ".php");
		
    } else if (file_exists(dirname(__FILE__) . "/exceptions/" . strtolower($class) . ".php")) {

        require_once(dirname(__FILE__) . "/exceptions/" . strtolower($class) . ".php");
    }
}