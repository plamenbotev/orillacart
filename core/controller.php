<?php

defined('_VALID_EXEC') or die('access denied');

abstract class controller extends BObject {

    protected $methods = array();
    protected $taskMap = array();
    protected $doTask = null;
    protected $view = null;
    protected $app = null;

    public function __construct() {

        list($app, $type) = explode("controller", strtolower(get_class($this)));

        $this->app = Factory::getComponent($app);

        $thisMethods = get_class_methods(get_class($this));

        $baseMethods = get_class_methods(__CLASS__);
        $methods = array_diff($thisMethods, $baseMethods);
   


        foreach ($methods as $method) {

            $this->methods[] = strtolower($method);
            // auto register public methods as tasks
            $this->taskMap[strtolower($method)] = $method;
        }
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

        $controllerClass = strtolower($prefix) . "Controller" . ucfirst($type);

        if (!class_exists($controllerClass)) {

            $path = Path::find(Controller::addIncludePath($prefix), $type . ".php");

            if ($path) {
                require_once $path;

                if (!class_exists($controllerClass)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $controller = new $controllerClass();

        if (method_exists($controller, 'init'))
            $controller->init();

        return $controller;
    }

    public function app() {

        return $this->app;
    }

    public function getModel($model) {
        return Model::getInstance($model, $this->app()->getName());
    }

    protected function getView($view, $type = 'html') {
        return $this->view = View::getInstance($view, $this->app()->getName(), $type);
    }

    protected function display($method = 'display') {

        if (!Request::is_internal() && !Request::is_ajax()) {
            if ($this->view instanceof view && method_exists($this->view, $method)) {
                factory::getApplication()->set_output($this->view, $method);
            } else
                throw new Exception("no view loaded");
        }else {
            if ($this->view instanceof view && method_exists($this->view, $method)) {
                $this->view->{$method}();
            } else {
                throw new Exception("no view loaded");
            }
        }
    }

    public function execute($task = null, $args = array()) {

        $task = strtolower($task);

        if (!empty($task) && in_array($task, $this->taskMap)) {
            $this->doTask = $task;

            return $this->$task($args);
        } else if (method_exists($this, '__default')) {
            $this->doTask = '__default';
            return $this->__default();
        } else {
            throw new BadMethodCallException("The supplied task is not available in the controller and there is no __default task handler.");
        }
    }

}
