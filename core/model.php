<?php

defined('_VALID_EXEC') or die('access denied');

abstract class model extends BObject {

    protected $name = null;
    protected $db = null;

    public function __construct() {
        $this->name = preg_replace("/(model)$/i", "", strtolower(get_class($this)));
        $this->db = Factory::getDBO();
    }

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
        $modelClass = ucfirst($type) . "Model";

        if (!class_exists($modelClass)) {

            $path = Path::find(Model::addIncludePath($prefix), $type . ".php");

            if ($path) {
                require_once $path;

                if (!class_exists($modelClass)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $model = new $modelClass();

        if (method_exists($model, 'init'))
            $model->init();

        return $model;
    }

}
