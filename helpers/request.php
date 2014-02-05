<?php

class Request {

    const NOTRIM = 1;
    const ALLOWRAW = 2;
    const ALLOWHTML = 3;

    static protected $internal_query = false;
    static $_REQUEST = array();
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

        if (is_array($q)) {
            $vars = $q;
        } else {
            parse_str($q, $vars);
        }
        if (!isset($vars['component'])) {
            throw new Exception("missing component");
        }



        self::$internal_query = $internal;

        $app = Factory::getApplication($vars['component']);



        unset($vars['component']);
        if (isset($vars['mode']))
            unset($vars['mode']);

        $remove = array();
        $restore = array();


        foreach ((array) $vars as $k => $v) {
            if (isset($_REQUEST[$k])) {
                $restore[$k] = request::getVar($k);
            } else {
                $remove[] = $k;
            }
            request::setVar($k, $v);
        }
        ob_start();


        do_action("before_run_" . $app->getName());
        $app->main();
        if (self::is_internal()) {


            $content = ob_get_clean();

            self::$internal_query = false;
            foreach ((array) $remove as $name) {

                foreach ((array) self::$_REQUEST[$name] as $k => $v) {


                    switch (strtoupper($k)) {
                        case 'SET.REQUEST':
                            unset($_REQUEST[$name]);
                            break;
                        case 'SET.GET' :
                            unset($_GET[$name]);

                            break;
                        case 'SET.POST' :
                            unset($_POST[$name]);

                            break;
                        case 'COOKIE' :
                            unset($_COOKIE[$name]);

                            break;
                        case 'FILES' :
                            unset($_FILES[$name]);
                            break;
                        case 'ENV' :
                            unset($_ENV['name']);
                            break;
                        case 'SERVER' :
                            unset($_SERVER['name']);
                            break;
                    }
                }
            }


            foreach ((array) $restore as $k => $v) {
                request::setVar($k, $v);
            }
        } else {
            Factory::getFramework()->view_dispatch();
            Factory::getFramework()->attachTheContent();
            $content = ob_get_clean();
        }

        //Factory::getFramework()->attachTheContent();
        return $content;
    }

    /**
     * Gets the request method
     *
     * @return string
     */
    static $request_method = null;

    static public function setMethod($m) {

        self::$method = $m;
    }

    static public function getMethod($override = null) {


        if (self::$request_method)
            return self::$request_method;

        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        return $method;
    }

    static public function ajaxMode() {

        static $set = null;
        if (is_null($set)) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $set = true;
        }
    }

    static public function is_ajax() {

        $request = ( !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') ? true : false;
		return $request;
    }

    static public function getVar($name, $default = null, $hash = 'default', $type = 'none', $mask = 0) {
        // Ensure hash and type are uppercase
        $hash = strtoupper($hash);
        if ($hash === 'METHOD') {
            $hash = strtoupper($_SERVER['REQUEST_METHOD']);
        }
        $type = strtoupper($type);
        $sig = $hash . $type . $mask;

        // Get the input hash
        switch ($hash) {
            case 'GET' :
                $input = &$_GET;
                break;
            case 'POST' :
                $input = &$_POST;
                break;
            case 'FILES' :
                $input = &$_FILES;
                break;
            case 'COOKIE' :
                $input = &$_COOKIE;
                break;
            case 'ENV' :
                $input = &$_ENV;
                break;
            case 'SERVER' :

                $input = &$_SERVER;
                break;
            default:
                $input = &$_REQUEST;
                $hash = 'REQUEST';
                break;
        }

        if (isset(self::$_REQUEST[$name]['SET.' . $hash]) && (self::$_REQUEST[$name]['SET.' . $hash] === true)) {
            // Get the variable from the input hash
            $var = (isset($input[$name]) && $input[$name] !== null) ? $input[$name] : $default;
            $var = self::_cleanVar($var, $mask, $type);

            //tuk
        } elseif (!isset(self::$_REQUEST[$name][$sig])) {
            if (isset($input[$name]) && $input[$name] !== null) {
                // Get the variable from the input hash and clean it
                $var = self::_cleanVar($input[$name], $mask, $type);

                // Handle magic quotes compatability
                if (get_magic_quotes_gpc() && ($var != $default) && ($hash != 'FILES')) {
                    $var = self::_stripSlashesRecursive($var);
                }

                self::$_REQUEST[$name][$sig] = $var;
            } elseif ($default !== null) {
                // Clean the default value
                $var = self::_cleanVar($default, $mask, $type);
            } else {
                $var = $default;
            }
        } else {
            $var = self::$_REQUEST[$name][$sig];
        }

        return $var;
    }

    static public function getInt($name, $default = 0, $hash = 'default') {
        return self::getVar($name, $default, $hash, 'int');
    }

    static public function getFloat($name, $default = 0.0, $hash = 'default') {
        return self::getVar($name, $default, $hash, 'float');
    }

    static public function getBool($name, $default = false, $hash = 'default') {
        return self::getVar($name, $default, $hash, 'bool');
    }

    static public function getWord($name, $default = '', $hash = 'default') {
        return self::getVar($name, $default, $hash, 'word');
    }

    static public function getCmd($name, $default = '', $hash = 'default') {
        return self::getVar($name, $default, $hash, 'cmd');
    }

    static public function getString($name, $default = '', $hash = 'default', $mask = 0) {
        // Cast to string, in case JREQUEST_ALLOWRAW was specified for mask
        return (string) self::getVar($name, $default, $hash, 'string', $mask);
    }

    static public function setVar($name, $value = null, $hash = 'method', $overwrite = true) {
        //If overwrite is true, makes sure the variable hasn't been set yet
        if (!$overwrite && array_key_exists($name, $_REQUEST)) {
            return $_REQUEST[$name];
        }

        // Clean global request var
        self::$_REQUEST[$name] = array();

        // Get the request hash value
        $hash = strtoupper($hash);
        if ($hash === 'METHOD') {
            $hash = strtoupper($_SERVER['REQUEST_METHOD']);
        }

        $previous = array_key_exists($name, $_REQUEST) ? $_REQUEST[$name] : null;

        switch ($hash) {
            case 'GET' :
                $_GET[$name] = $value;
                $_REQUEST[$name] = $value;
                break;
            case 'POST' :
                $_POST[$name] = $value;
                $_REQUEST[$name] = $value;
                break;
            case 'COOKIE' :
                $_COOKIE[$name] = $value;
                $_REQUEST[$name] = $value;
                break;
            case 'FILES' :
                $_FILES[$name] = $value;
                break;
            case 'ENV' :
                $_ENV['name'] = $value;
                break;
            case 'SERVER' :
                $_SERVER['name'] = $value;
                break;
        }

        // Mark this variable as 'SET'
        self::$_REQUEST[$name]['SET.' . $hash] = true;
        self::$_REQUEST[$name]['SET.REQUEST'] = true;

        return $previous;
    }

    static public function get($hash = 'default', $mask = 0) {
        $hash = strtoupper($hash);

        if ($hash === 'METHOD') {
            $hash = strtoupper($_SERVER['REQUEST_METHOD']);
        }

        switch ($hash) {
            case 'GET' :
                $input = $_GET;
                break;

            case 'POST' :
                $input = $_POST;
                break;

            case 'FILES' :
                $input = $_FILES;
                break;

            case 'COOKIE' :
                $input = $_COOKIE;
                break;

            case 'ENV' :
                $input = &$_ENV;
                break;

            case 'SERVER' :
                $input = &$_SERVER;
                break;

            default:
                $input = $_REQUEST;
                break;
        }

        $result = self::_cleanVar($input, $mask);

        // Handle magic quotes compatability
        if (get_magic_quotes_gpc() && ($hash != 'FILES')) {
            $result = self::_stripSlashesRecursive($result);
        }

        return $result;
    }

    static public function set($array, $hash = 'default', $overwrite = true) {
        foreach ($array as $key => $value) {
            self::setVar($key, $value, $hash, $overwrite);
        }
    }

    static public function clean() {
        self::_cleanArray($_FILES);
        self::_cleanArray($_ENV);
        self::_cleanArray($_GET);
        self::_cleanArray($_POST);
        self::_cleanArray($_COOKIE);
        self::_cleanArray($_SERVER);

        if (isset($_SESSION)) {
            self::_cleanArray($_SESSION);
        }

        $REQUEST = $_REQUEST;
        $GET = $_GET;
        $POST = $_POST;
        $COOKIE = $_COOKIE;
        $FILES = $_FILES;
        $ENV = $_ENV;
        $SERVER = $_SERVER;

        if (isset($_SESSION)) {
            $SESSION = $_SESSION;
        }

        foreach ($GLOBALS as $key => $value) {
            if ($key != 'GLOBALS') {
                unset($GLOBALS [$key]);
            }
        }
        $_REQUEST = $REQUEST;
        $_GET = $GET;
        $_POST = $POST;
        $_COOKIE = $COOKIE;
        $_FILES = $FILES;
        $_ENV = $ENV;
        $_SERVER = $SERVER;

        if (isset($SESSION)) {
            $_SESSION = $SESSION;
        }

        // Make sure the request hash is clean on file inclusion
        self::$_REQUEST = array();
    }

    protected static function _cleanArray(&$array, $globalise = false) {
        static $banned = array('_files', '_env', '_get', '_post', '_cookie', '_server', '_session', 'globals');

        foreach ($array as $key => $value) {
            // PHP GLOBALS injection bug
            $failed = in_array(strtolower($key), $banned);

            // PHP Zend_Hash_Del_Key_Or_Index bug
            $failed |= is_numeric($key);
            if ($failed) {
                throw new Exception('Illegal variable <b>' . implode('</b> or <b>', $banned) . '</b> passed to script.');
            }
            if ($globalise) {
                $GLOBALS[$key] = $value;
            }
        }
    }

    protected static function _cleanVar($var, $mask = 0, $type = null) {
        // Static input filters for specific settings
        static $noHtmlFilter = null;
        static $safeHtmlFilter = null;

        // If the no trim flag is not set, trim the variable
        if (!($mask & 1) && is_string($var)) {
            $var = trim($var);
        }

        // Now we handle input filtering
        if ($mask & 2) {
            // If the allow raw flag is set, do not modify the variable
            $var = $var;
        } elseif ($mask & 4) {
            // If the allow html flag is set, apply a safe html filter to the variable
            if (is_null($safeHtmlFilter)) {
                $safeHtmlFilter = & FilterInput::getInstance(null, null, 1, 1);
            }
            $var = $safeHtmlFilter->clean($var, $type);
        } else {
            // Since no allow flags were set, we will apply the most strict filter to the variable
            if (is_null($noHtmlFilter)) {
                $noHtmlFilter = & FilterInput::getInstance(/* $tags, $attr, $tag_method, $attr_method, $xss_auto */);
            }
            $var = $noHtmlFilter->clean($var, $type);
        }
        return $var;
    }

    protected static function _stripSlashesRecursive($value) {
        $value = is_array($value) ? array_map(array(__CLASS__, '_stripSlashesRecursive'), $value) : stripslashes($value);
        return $value;
    }

}