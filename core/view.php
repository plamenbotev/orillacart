<?php

defined('_VALID_EXEC') or die('access denied');

abstract class view extends BObject {

    protected $_name = null;
    protected $_models = array();
    protected $_tpl = 'default';
    protected $_path = null;
    protected $_app = null;

    protected function setPath($p) {
        $this->_path = $p;
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

    public static function getInstance($view, $prefix, $type = 'html') {

        static $cache = array();

        if (array_key_exists(md5($view . $prefix . $type), $cache)) {
            return $cache[md5($view . $prefix . $type)];
        }


        $type = preg_replace('/[^A-Z0-9_\.-]/i', '', $type);
        $view = preg_replace('/[^A-Z0-9_\.-]/i', '', $view);
        $viewClass = strtolower($prefix) . "View" . ucfirst($view);

        $file = "view." . $type . ".php";

        if (!class_exists($viewClass)) {

            $paths = View::addIncludePath($prefix);

            foreach ((array) $paths as $k => $path) {
                $paths[$k] = $path . DS . $view;
            }


            $path = Path::find($paths, $file);

            if ($path) {
                require_once $path;

                if (!class_exists($viewClass)) {
                    return false;
                }
            } else {
                return false;
            }
        }


        $obj = new $viewClass();
        if (method_exists($obj, 'setApp'))
            $obj->setApp(Factory::getComponent($prefix));
        if (method_exists($obj, 'init'))
            $obj->init();

        $obj->setPath(dirname($path));
        $cache[md5($view . $prefix . $type)] = $obj;
        return $cache[md5($view . $prefix . $type)];
    }

    public function setMessage($msg, $type = 'info') {
        Factory::getComponent()->setMessage($msg, $type);
    }

    public function __construct() {

        list($app, $type) = explode("view", strtolower(get_class($this)));

        $this->_app = Factory::getComponent($app);


        $this->_name = preg_replace("/(view)$/i", "", strtolower(get_class($this)));
    }

    final public function app() {
        return $this->_app;
    }

	final public function setModel(model $model) {

        if ($model instanceof model) {

            return $this->_models[strtolower(get_class($model))] = $model;
        }

        return false;
    }

    final protected function getModel($model) {

        if (array_key_exists(strtolower($model . "model"), $this->_models))
            return $this->_models[strtolower($model . "model")];

        return false;
    }

    private function __clone() {
        
    }

    final public function assign() {



        // get the arguments; there may be 1 or 2.
        $arg0 = @func_get_arg(0);
        $arg1 = @func_get_arg(1);

        // assign by object
        if (is_object($arg0)) {
            // assign public properties
            foreach (get_object_vars($arg0) as $key => $val) {
                if (substr($key, 0, 1) != '_') {
                    $this->$key = $val;
                }
            }
            return true;
        }

        // assign by associative array
        if (is_array($arg0)) {
            foreach ($arg0 as $key => $val) {
                if (substr($key, 0, 1) != '_') {
                    $this->$key = $val;
                }
            }
            return true;
        }

        // assign by string name and mixed value.
        // we use array_key_exists() instead of isset() becuase isset()
        // fails if the value is set to null.
        if (is_string($arg0) && substr($arg0, 0, 1) != '_' && func_num_args() > 1) {
            $this->$arg0 = $arg1;



            return true;
        }

        // $arg0 was not object, array, or string.
        return false;
    }

    final public function assignRef($key, &$val) {
        if (is_string($key) && substr($key, 0, 1) != '_') {
            $this->$key = & $val;
            return true;
        }

        return false;
    }

    public function display() {
			        
    }

    public function loadTemplate($tpl = 'default') {

        static $overrides = array();

        do_action("view_display", get_class($this), $tpl);

        $paths = array();

        $com = strtolower($this->app()->getName());
        //search for overrides



        if (array_key_exists($com, $overrides)) {
            $paths = (array) $overrides[$com];
        } else {


            $view_path = str_replace(path::clean($this->app()->getComponentPath() . DS . "views"), "", path::clean($this->_path));

            $view_path = path::clean($view_path);

            //add wp_content also with lower priority
            $paths[] = WP_CONTENT_DIR . DS . "com_" . $com . "_" . $this->app()->getMode() . $view_path;
            //add the template path
            $paths[] = get_stylesheet_directory() . DS . "com_" . $com . "_" . $this->app()->getMode() . $view_path;


            $paths = (array) apply_filters('override_' . $com."_".$this->app()->getMode(), $paths);


            $overrides[$com] = (array) $paths;
        }

        $paths = array_reverse($paths);

        $paths = (array) apply_filters('edit_template_paths_' . $com."_".$this->app()->getMode(), $paths);

        $the_path = null;

        foreach ((array) $paths as $path) {
            $path = realpath($path);

            if (is_dir($path) && file_exists($path . DS . $tpl . ".tpl.php")) {
                $the_path = $path;
                break;
            }
        }

        if ($the_path) {

            require($path . DS . $tpl . ".tpl.php");
            return true;
        } else {
            $path = $this->_path . "/templates";

            if (file_exists($path . "/" . $tpl . ".tpl.php")) {

                require($path . "/" . $tpl . ".tpl.php");

                return true;
            }
        }

        throw new Exception("template:" . $tpl . __(" file cant be located!", "com_shop"));
    }

    protected function escape($val) {
        return strings::htmlentities($val);
    }

}
