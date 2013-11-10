<?php

class helper {

    public static function addIncludePath($prefix, $path = '') {
        static $paths;

        if (!isset($paths)) {
            $paths = array();
        }


        if (!isset($paths[$prefix])) {
            $paths[$prefix] = array();
        }

        if (!empty($path)) {

            if (!in_array($path, $paths[$prefix])) {
                array_unshift($paths[$prefix], Path::clean($path));
            }
        }

        return $paths[$prefix];
    }


    public static function getInstance($type, $prefix) {
        $type = preg_replace('/[^A-Z0-9_\.-]/i', '', $type);
        $helperClass = ucfirst($type);

        if (!class_exists($helperClass)) {

            $path = Path::find(helper::addIncludePath($prefix), $type . ".php");

            if ($path) {
                require_once $path;

                if (!class_exists($helperClass)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $args = func_get_args();
        unset($args[0], $args[1]);

        $o = null;
        if (method_exists($helperClass, 'getInstance')) {
            $o = call_user_func_array(array($helperClass,"getInstance"), $args);
        } else {
            $o = new $helperClass($args);
        }
      
        if(method_exists($o,'init')) $o->init();
        return $o;
    }
}