<?php

defined('_VALID_EXEC') or die('access denied');

abstract class component extends app_object {

    protected $name = null;
    protected $componentBase = null;
    protected $base_path = null;
    protected $rel_path = null;
    protected $component_url = null;
    protected $sys_messages = array("admin" => array(), "front" => array());
    protected $sys_errors = array("admin" => array(), "front" => array());
    protected $contex_errors = array("admin" => array(), "front" => array());
    protected $params = null;
    protected $mode = null;

    abstract static public function register_component($components);

    public function add_custom_error($contex, $error, $use_session = true) {

        if (!isset($this->contex_errors[$this->mode][$contex])) {
            $this->contex_errors[$this->mode][$contex] = array();
        }

        if (in_array($msg, (array) $this->contex_errors[$this->mode][$contex]))
            return false;


        $this->contex_errors[$this->mode][$contex][] = $error;
        if ($use_session) {
            if (!isset($_SESSION['messages']['custom_messages'][$this->mode]))
                $_SESSION['messages']['custom_messages'][$this->mode] = array();
            $_SESSION['messages']['custom_messages'][$this->mode][$context][] = $error;
        }
    }

    public function count_custom_errors($contex) {
        return count($this->contex_errors[$this->mode][$contex]);
    }

    public function get_custom_error($contex) {
        if (!isset($this->contex_errors[$this->mode][$contex])) {
            return array();
        }
        return $this->contex_errors[$this->mode][$contex];
    }

    public function clear_error_contex($contex) {
        if (isset($this->contex_errors[$this->mode][$contex])) {
            unset($this->contex_errors[$this->mode][$contex]);
            unset($_SESSION['messages']['custom_messages'][$this->mode][$context]);
        }
    }

    public function clearMessages() {


        $this->sys_messages[$this->mode] = array();
        $this->sys_errors[$this->mode] = array();
        unset($_SESSION['messages']['messages'][$this->mode], $_SESSION['messages']['errors'][$this->mode]);
    }

    public function getParams() {

        static $cache = null;

        if ($cache)
            return $cache;

        $path = $this->getComponentPath() . DS . ".." . DS . "params.php";


        if (file_exists($path)) {

            require_once($path);

            $class = $this->getName() . 'Params';

            if (!class_exists($class))
                return false;

            $reflection = new ReflectionClass($class);


            if (is_object($reflection) && $reflection instanceof ReflectionClass && $reflection->isSubclassOf('parameters')) {

                $cache = new $class($this);

                if (method_exists($cache, 'init'))
                    $cache->init();
                return $cache;
            }
            else {

                return false;
            }
        } else {

            return false;
        }
    }

    public function getHelper($helper) {


        return Helper::getInstance($helper, $this->getName());
    }

    public function getTable($table) {

        $table = Table::getInstance($table, $this->getName());


        return $table;
    }

    final public function setMessage($msg, $use_session = true) {
        if (in_array($msg, $this->sys_messages[$this->mode]))
            return false;
        if ($use_session) {
            $_SESSION['messages']['messages'][$this->mode][] = $msg;
        }
        $this->sys_messages[$this->mode][] = $msg;
    }

    final public function addError($msg, $use_session = true) {

        if (in_array($msg, $this->sys_errors[$this->mode]))
            return false;
        if ($use_session) {
            $_SESSION['messages']['errors'][$this->mode][] = $msg;
        }
        $this->sys_errors[$this->mode][] = $msg;
    }

    final public function errorsCount() {
        return (int) sizeof($this->sys_errors[$this->mode]);
    }

    final public function messagesCount() {
        return (int) sizeof($this->sys_messages[$this->mode]);
    }

    final public function getMessages() {


        if (empty($this->sys_messages[$this->mode]))
            return false;

        return (array) $this->sys_messages[$this->mode];
    }

    final public function getErrors() {
        return (array) $this->sys_errors[$this->mode];
    }

    abstract public function main();

    public function getName() {
        return $this->name;
    }

    public function getComponentUrl() {


        return $this->component_url;
    }

    public function getComponentPath() {


        return $this->componentBase;
    }

    public function close() {


        exit();
    }

    public function redirect($url, $moved = true) {

        if (headers_sent()) {
            echo "<script>document.location.href='$url';</script>\n";
        } else {
            header($moved ? 'HTTP/1.1 301 Moved Permanently' : 'HTTP/1.1 303 See other');
            header('Location: ' . $url);
        }
        $this->close();
    }

    public function show_messages() {

        if (!request::is_ajax()) {

            $error_messages = $this->getErrors();

            $messages .= "<div id='" . $this->getName() . "_info' class='system_messagess' style='background-color:#FFD559; padding:7px; ";
            $messages .= (!$this->messagesCount() & !$this->errorsCount()) ? "display:none;" : "";
            $messages .= "'>";

            if ($this->errorsCount()) {


                foreach ((array) $error_messages as $msg) {

                    $messages .= "<div class='system_message_error'>" . strings::cleanText($msg) . "</div>";
                }
            }

            $sys_messages = $this->getMessages();


            if ($this->messagesCount()) {


                foreach ((array) $sys_messages as $msg) {

                    $messages .= "<div class='system_message_info'>" . strings::cleanText($msg) . "</div>";
                }
            }
            $messages .= "</div>";
            $this->clearMessages();

            echo $messages;
        }
    }

    public function init() {
        
    }

    public function __construct() {


        static $init = false;

        if (Framework::is_admin()) {
            $this->mode = 'admin';
        } else {

            $this->mode = 'front';
        }




        if (!$init) {
            session_start();
            if (isset($_SESSION['messages']['errors'][$this->mode]) && !empty($_SESSION['messages']['errors'][$this->mode])) {
                $this->sys_errors[$this->mode] = (array) array_merge((array) $_SESSION['messages']['errors'][$this->mode], (array) $this->sys_errors[$this->mode]);

                // unset($_SESSION['messages']['errors']);
            }
            if (isset($_SESSION['messages']['messages'][$this->mode]) && !empty($_SESSION['messages']['messages'][$this->mode])) {
                $this->sys_messages[$this->mode] = (array) array_merge((array) $_SESSION['messages']['messages'][$this->mode], (array) $this->sys_messages[$this->mode]);

                //  unset($_SESSION['messages']['messages']);
            }

            if (isset($_SESSION['messages']['custom_messages'][$this->mode]) && !empty($_SESSION['messages']['custom_messages'][$this->mode])) {
                $this->contex_errors[$this->mode] = (array) array_merge((array) $_SESSION['messages']['custom_messages'][$this->mode], (array) $this->contex_errors[$this->mode]);

                //  unset($_SESSION['messages']['custom_messages']);
            }

            add_action('admin_notices', array($this, 'show_messages'));
        }
        $init = true;

        $this->name = strtolower(get_class($this));

        $path = '';
        $base_path = '';
        $mode = $this->mode;

        $components = factory::getFramework()->get_active_components();

        foreach ((array) $components as $cpath => $class) {
            if ($class == get_class($this)) {
                $path = $cpath . "/" . $mode;
                $base_path = $cpath;
            }
        }

        $this->base_path = $base_path;

        $plugin_dir_name = plugin_basename($base_path);
        $this->rel_path = $plugin_dir_name;
        if (is_dir($path)) {
            $this->componentBase = $path;
            $this->component_url = WP_PLUGIN_URL . '/' . $plugin_dir_name;
            add_action("init", array($this, "load_language"));
        } else {

            throw new Exception("cant determine component directory for component:" . get_class($this));
        }
    }

    public function load_language() {
        
        $locale = apply_filters( 'plugin_locale', get_locale(), "com_".$this->getName() );
      
	load_textdomain( "com_".$this->getName(), WP_LANG_DIR . "/com_".$this->getName()."/com_".$this->getName()."-".$locale."mo" );
           
        load_plugin_textdomain("com_".$this->getName(),false, $this->rel_path."/languages");
    
     
    }

    public function getAssetsPath() {


        return str_replace('\\', '/', $this->base_path . DS . ".." . DS . "assets");
    }

    public function getAssetsUrl() {


        return $this->component_url . "/assets";
    }

    protected function getController($name) {


        $c = Controller::getInstance($name, $this->getName());
        if (!$c) {
            throw new not_found_404("Controller not found");
        }

        return $c;
    }

    static public function get_wp_pages($com = null) {
        static $cache = array();

        if (!$com)
            return array();

        if (!empty($cache[$com]))
            return $cache[$com];

        $params = Factory::getApplication($com)->getParams();
        $page_id = $params->get('page_id');

        if (!$page_id)
            return array();

        $db = Factory::getDBO();
        $db2 = Factory::getDBO();

        $que = sprintf("SELECT id,post_content,post_name,post_parent  FROM `#_posts` WHERE post_status='publish' AND post_type='page' AND ID = %s ", (int) $page_id);

        $db->setQuery($que);
        if (!$db->getResource()) {
            throw new Exception($db->getErrorString());
        }

        $o = $db->nextObject();

        $path = array();
        $path[] = $o->post_name;
        $parent = $o->post_parent;
        if ($parent) {
            while (true) {
                $db2->setQuery(sprintf("SELECT * FROM #_posts WHERE id = %s", (int) $parent));
                if (!$db2->getResource()) {
                    throw new Exception($db2->getErrorString());
                }
                $row = $db2->nextObject();
                if (!$row)
                    break;
                $path[] = $row->post_name;
                $parent = $row->post_parent;
            }
        }

        if (!empty($path)) {
            $cache[$com][] = implode('/', array_reverse($path));
        }

        if (!array_key_exists(0, $cache[$com])) {
            $cache[$com][] = $com;
        }

        return $cache[$com];
    }

}