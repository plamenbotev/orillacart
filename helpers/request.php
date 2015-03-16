<?php

class Request {

    static protected $internal_query = false;
    static protected $wp_query = null;
    static protected $wp_request = array();
    static protected $modified_wp_request = array();
    protected $query = null;

    public function __construct($query) {
        $this->query = $query;
    }

    public function exec() {
        return self::execute($this->query);
    }

    static public function is_internal() {
        return (bool) self::$internal_query;
    }

    static public function get_wp_original_query() {
        return self::$wp_query;
    }

    static public function get_wp_original_request() {
        return self::$wp_request;
    }

    static public function &get_wp_request() {
        return self::$modified_wp_request;
    }

    static public function set_wp_request($r) {
        return self::$modified_wp_request = (array) $r;
    }

    static public function set_wp_var($k, $v) {
        self::$modified_wp_request[$k] = $v;
    }

    static public function get_wp_var($k) {
        return isset(self::$modified_wp_request[$k]) ? self::$modified_wp_request[$k] : false;
    }

    static public function parse_request($request) {

        if (self::$wp_query)
            return $request;
        self::$modified_wp_request = self::$wp_request = $request;
        self::$wp_query = new WP_Query();

        self::$wp_query->parse_query($request);

        return $request;
    }

    static public function execute($q, $internal = true) {

        $vars = array();
        if (is_array($q)) {
            $vars = $q;
        } else {
            parse_str($q, $vars);
        }
        if (!isset($vars['component'])) {
            throw new Exception("missing component");
        }



        self::$internal_query = $internal;

        $app = Factory::getComponent($vars['component']);



        unset($vars['component']);
        if (isset($vars['mode']))
            unset($vars['mode']);

        //override the input

        $input = clone Factory::getApplication()->getInput();

        foreach ((array) $vars as $k => $v) {
            $input->set($k, $v);

            $input->post->set($k, $v);
            $input->request->set($k, $v);
        }

        Factory::getApplication()->setInput($input);


        ob_start();


        do_action("before_run_" . $app->getName());
        $app->main();

        if (self::is_internal()) {


            $content = ob_get_clean();

            self::$internal_query = false;
        } else {
            Factory::getApplication()->view_dispatch();
            Factory::getApplication()->attachTheContent();
            $content = ob_get_clean();
        }
        //restore the original input
        Factory::getApplication()->setInput(null);


        return $content;
    }

    static public function ajaxMode() {

        static $set = null;
        if (is_null($set)) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $set = true;
        }
    }

    static public function is_ajax() {

        $request = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') ? true : false;
        return $request;
    }

}
