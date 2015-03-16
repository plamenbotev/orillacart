<?php

defined('_VALID_EXEC') or die('access denied');

abstract class parameters {

    private $component = null;
    private $props = null;
    private $reflect = null;
    private $readOnly = array();

    public function __construct(component $app) {

        $component = $app->getName();

        $this->reflect = new ReflectionClass($this);

        $props = $this->reflect->getProperties(ReflectionProperty::IS_PROTECTED);

        foreach ($props as $p) {

            $this->readOnly[] = $p->getName();
        }

        $props = $this->reflect->getProperties(ReflectionProperty::IS_PUBLIC);

        $this->props = array();
        foreach ($props as $p) {

            $this->props[$p->getName()] = $this->{$p->getName()};
        }

        unset($props);

        $this->component = $component;


        $params = array();

        $params = (array) get_option($component . "_parameters");



        foreach ($params as $k => $v) {

            if (array_key_exists($k, $this->props)) {


                $this->props[$k] = $v;
            }
        }
    }

    protected function check() {


        return true;
    }

    public function bind(&$var) {


        foreach ($this->props as $k => $p) {

            if (defined(get_class($this) . '::' . $k) || in_array($k, $this->readOnly))
                continue;


            if (isset($var[$k])) {

                $this->props[$k] = $var[$k];
            }
        }

        return true;
    }

    final public function save() {

        $params = array();
        $status = $this->check();
        if ($this->check() !== true)
            return $status;

        if (method_exists($this, 'filter'))
            $this->props = $this->filter($this->props);
        $this->props = apply_filters('before_parameters_save_' . $this->component, $this->props);


        update_option($this->component . "_parameters", $this->props);
        //replace the wp object cache
        wp_cache_replace("params", $this, "com_" . $this->component);
        return true;
    }

    public function get($p, $default = false) {

        if (defined(get_class($this) . '::' . $p)) {

            return constant(get_class($this) . '::' . $p);
        } else if (in_array($p, $this->readOnly)) {

            return $this->{$p};
        } else if (array_key_exists($p, $this->props)) {

            if ($default) {
                return $this->$p;
            }

            return $this->props[$p];
        }

        return false;
    }

    public function set($p, $v = null) {

        if (defined(get_class($this) . '::' . $p)) {

            throw new Exception("the property \"{$p}\" is a constant value and cannot be changed!");
        }
        if (in_array($p, $this->readOnly)) {

            throw new Exception("the property \"{$p}\" is a read only property!!");
        }

        if (array_key_exists($p, $this->props)) {

            return $this->props[$p] = $v;
        }
        return false;
    }

}
