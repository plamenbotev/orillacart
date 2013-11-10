<?php

defined('_VALID_EXEC') or die('access denied');

class URI extends app_object {

    protected $_uri = null;
    protected $_scheme = null;
    protected $_host = null;
    protected $_port = null;
    protected $_user = null;
    protected $_pass = null;
    protected $_path = null;
    protected $_query = null;
    protected $_fragment = null;
    protected $_vars = array();
    protected static $instances = array();
    protected static $base = array();
    protected static $root = array();
    protected static $current;

    public function __construct($uri = null) {
        if (!is_null($uri)) {
            $this->parse($uri);
        }
    }

    public function __toString() {
        return $this->toString();
    }

    public static function getInstance($uri = 'SERVER') {

        if (empty(self::$instances[$uri])) {
            // Are we obtaining the URI from the server?
            if ($uri == 'SERVER') {
                // Determine if the request was over SSL (HTTPS).
                if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) != 'off')) {
                    $https = 's://';
                } else {
                    $https = '://';
                }

                // Since we are assigning the URI from the server variables, we first need
                // to determine if we are running on apache or IIS.  If PHP_SELF and REQUEST_URI
                // are present, we will assume we are running on apache.

                if (!empty($_SERVER['PHP_SELF']) && !empty($_SERVER['REQUEST_URI'])) {

// To build the entire URI we need to prepend the protocol, and the http host
                    // to the URI string.
                    $theURI = 'http' . $https . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                } else {

                    // Since we do not have REQUEST_URI to work with, we will assume we are
                    // running on IIS and will therefore need to work some magic with the SCRIPT_NAME and
                    // QUERY_STRING environment variables.
                    // IIS uses the SCRIPT_NAME variable instead of a REQUEST_URI variable... thanks, MS
                    $theURI = 'http' . $https . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

                    // If the query string exists append it to the URI string

                    if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
                        $theURI .= '?' . $_SERVER['QUERY_STRING'];
                    }
                }
            } else {

                // We were given a URI
                $theURI = $uri;
            }

            // Create the new JURI instance

            self::$instances[$uri] = new URI($theURI);
        }
        return self::$instances[$uri];
    }

    public static function base($pathonly = false) {
        // Get the base request path.
        if (empty(self::$base)) {

            $live_site = get_option('siteurl');
            if (trim($live_site) != '') {
                $uri = self::getInstance($live_site);
                self::$base['prefix'] = $uri->toString(array('scheme', 'host', 'port'));
                self::$base['path'] = rtrim($uri->toString(array('path')), '/\\');

                if (Factory::getFramework()->is_admin()) {
                    self::$base['path'] .= '/wp-admin';
                }
            } else {
                $uri = self::getInstance();
                self::$base['prefix'] = $uri->toString(array('scheme', 'host', 'port'));

                if (strpos(php_sapi_name(), 'cgi') !== false && !ini_get('cgi.fix_pathinfo') && !empty($_SERVER['REQUEST_URI'])) {
                    // PHP-CGI on Apache with "cgi.fix_pathinfo = 0"
                    // We shouldn't have user-supplied PATH_INFO in PHP_SELF in this case
                    // because PHP will not work with PATH_INFO at all.
                    $script_name = $_SERVER['PHP_SELF'];
                } else {
                    // Others
                    $script_name = $_SERVER['SCRIPT_NAME'];
                }

                self::$base['path'] = rtrim(dirname($script_name), '/\\');
            }
        }

        return $pathonly === false ? self::$base['prefix'] . self::$base['path'] . '/' : self::$base['path'];
    }

    public static function root($pathonly = false, $path = null) {
        // Get the scheme
        if (empty(self::$root)) {
            $uri = self::getInstance(self::base());
            self::$root['prefix'] = $uri->toString(array('scheme', 'host', 'port'));
            self::$root['path'] = rtrim($uri->toString(array('path')), '/\\');
        }

        // Get the scheme
        if (isset($path)) {
            self::$root['path'] = $path;
        }

        return $pathonly === false ? self::$root['prefix'] . self::$root['path'] . '/' : self::$root['path'];
    }

    public static function current() {
        // Get the current URL.
        if (empty(self::$current)) {
            $uri = self::getInstance();

            self::$current = $uri->toString(array('scheme', 'host', 'port', 'path'));
        }

        return self::$current;
    }

    public static function reset() {
        self::$instances = array();
        self::$base = array();
        self::$root = array();
        self::$current = '';
    }

    public function parse($uri) {
        // Initialise variables
        $retval = false;

        // Set the original URI to fall back on
        $this->_uri = $uri;

        // Parse the URI and populate the object fields.  If URI is parsed properly,
        // set method return value to true.

        if ($_parts = Strings::parse_url($uri)) {
            $retval = true;
        }

        // We need to replace &amp; with & for parse_str to work right...
        if (isset($_parts['query']) && strpos($_parts['query'], '&amp;')) {
            $_parts['query'] = str_replace('&amp;', '&', $_parts['query']);
        }

        $this->_scheme = isset($_parts['scheme']) ? $_parts['scheme'] : null;
        $this->_user = isset($_parts['user']) ? $_parts['user'] : null;
        $this->_pass = isset($_parts['pass']) ? $_parts['pass'] : null;
        $this->_host = isset($_parts['host']) ? $_parts['host'] : null;
        $this->_port = isset($_parts['port']) ? $_parts['port'] : null;
        $this->_path = isset($_parts['path']) ? $_parts['path'] : null;
        $this->_query = isset($_parts['query']) ? $_parts['query'] : null;
        $this->_fragment = isset($_parts['fragment']) ? $_parts['fragment'] : null;

        // Parse the query

        if (isset($_parts['query'])) {
            parse_str($_parts['query'], $this->_vars);
        }
        return $retval;
    }

    public function toString($parts = array('scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment')) {
        // Make sure the query is created
        $query = $this->getQuery();

        $uri = '';
        $uri .= in_array('scheme', $parts) ? (!empty($this->_scheme) ? $this->_scheme . '://' : '') : '';
        $uri .= in_array('user', $parts) ? $this->_user : '';
        $uri .= in_array('pass', $parts) ? (!empty($this->_pass) ? ':' : '') . $this->_pass . (!empty($this->_user) ? '@' : '') : '';
        $uri .= in_array('host', $parts) ? $this->_host : '';
        $uri .= in_array('port', $parts) ? (!empty($this->_port) ? ':' : '') . $this->_port : '';
        $uri .= in_array('path', $parts) ? $this->_path : '';
        $uri .= in_array('query', $parts) ? (!empty($query) ? '?' . $query : '') : '';
        $uri .= in_array('fragment', $parts) ? (!empty($this->_fragment) ? '#' . $this->_fragment : '') : '';

        return $uri;
    }

    public function setVar($name, $value) {
        $tmp = @$this->_vars[$name];
        $this->_vars[$name] = $value;

        // Empty the query
        $this->_query = null;

        return $tmp;
    }

    public function hasVar($name) {
        return array_key_exists($name, $this->_vars);
    }

    public function getVar($name, $default = null) {
        if (array_key_exists($name, $this->_vars)) {
            return $this->_vars[$name];
        }
        return $default;
    }

    public function delVar($name) {
        if (array_key_exists($name, $this->_vars)) {
            unset($this->_vars[$name]);

            //empty the query
            $this->_query = null;
        }
    }

    public function setQuery($query) {
        if (is_array($query)) {
            $this->_vars = $query;
        } else {
            if (strpos($query, '&amp;') !== false) {
                $query = str_replace('&amp;', '&', $query);
            }
            parse_str($query, $this->_vars);
        }

        // Empty the query
        $this->_query = null;
    }

    public function getQuery($toArray = false) {
        if ($toArray) {
            return $this->_vars;
        }

        // If the query is empty build it first
        if (is_null($this->_query)) {
            $this->_query = self::buildQuery($this->_vars);
        }

        return $this->_query;
    }

    public static function buildQuery($params) {
        if (!is_array($params) || count($params) == 0) {
            return false;
        }

        return urldecode(http_build_query($params, '', '&'));
    }

    public function getScheme() {
        return $this->_scheme;
    }

    public function setScheme($scheme) {
        $this->_scheme = $scheme;
    }

    public function getUser() {
        return $this->_user;
    }

    public function setUser($user) {
        $this->_user = $user;
    }

    public function getPass() {
        return $this->_pass;
    }

    public function setPass($pass) {
        $this->_pass = $pass;
    }

    public function getHost() {
        return $this->_host;
    }

    public function setHost($host) {
        $this->_host = $host;
    }

    public function getPort() {
        return (isset($this->_port)) ? $this->_port : null;
    }

    public function setPort($port) {
        $this->_port = $port;
    }

    public function getPath() {
        return $this->_path;
    }

    public function setPath($path) {
        $this->_path = $this->_cleanPath($path);
    }

    public function getFragment() {
        return $this->_fragment;
    }

    public function setFragment($anchor) {
        $this->_fragment = $anchor;
    }

    public function isSSL() {
        return $this->getScheme() == 'https' ? true : false;
    }

    public static function isInternal($url) {
        $uri = self::getInstance($url);
        $base = $uri->toString(array('scheme', 'host', 'port', 'path'));
        $host = $uri->toString(array('scheme', 'host', 'port'));
        if (stripos($base, self::base()) !== 0 && !empty($host)) {
            return false;
        }
        return true;
    }

    protected function _cleanPath($path) {
        $path = explode('/', preg_replace('#(/+)#', '/', $path));

        for ($i = 0, $n = count($path); $i < $n; $i++) {
            if ($path[$i] == '.' or $path[$i] == '..') {
                if (($path[$i] == '.') or ($path[$i] == '..' and $i == 1 and $path[0] == '')) {
                    unset($path[$i]);
                    $path = array_values($path);
                    $i--;
                    $n--;
                } elseif ($path[$i] == '..' and ($i > 1 or ($i == 1 and $path[0] != ''))) {
                    unset($path[$i]);
                    unset($path[$i - 1]);
                    $path = array_values($path);
                    $i -= 2;
                    $n -= 2;
                }
            }
        }

        return implode('/', $path);
    }
}