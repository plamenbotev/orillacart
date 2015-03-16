<?php

defined('_VALID_EXEC') or die('access denied');

abstract class component extends BObject implements SelfRegisterable {
  
    protected $name = null;
    protected $componentBase = null;
    protected $root_path = null;
    protected $rel_path = null;
    protected $component_url = null;
    protected $sys_messages = array("admin" => array(), "front" => array());
    protected $sys_errors = array("admin" => array(), "front" => array());
    protected $contex_errors = array("admin" => array(), "front" => array());
    protected $params = null;
    protected $mode = null;

  

    public function getComponentRootPath() {
        return $this->root_path;
    }

    public function getMode() {
        return $this->mode;
    }

    public function addCustomError($contex, $error, $use_session = true) {

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

    public function countCustomErrors($contex) {
        return count($this->contex_errors[$this->mode][$contex]);
    }

    public function getCustomError($contex) {
        if (!isset($this->contex_errors[$this->mode][$contex])) {
            return array();
        }
        return $this->contex_errors[$this->mode][$contex];
    }

    public function clearErrorContex($contex) {
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


        $path = realpath($this->root_path . DS . "params.php");

        if (file_exists($path)) {

            require_once($path);
        } else {

            return false;
        }

        $result = wp_cache_get('params', strtolower("com_" . $this->getName()));

        if ($result !== false) {

            return $result;
        }



        $class = $this->getName() . 'Params';

        if (!class_exists($class)) {

            return false;
        }

        $reflection = new ReflectionClass($class);


        if (is_object($reflection) && $reflection instanceof ReflectionClass && $reflection->isSubclassOf('parameters')) {

            $cache = new $class($this);

            if (method_exists($cache, 'init'))
                $cache->init();


            wp_cache_set("params", $cache, strtolower("com_" . $this->getName()));
            return $cache;
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

    public function main() {
        return;
    }

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

    public function showMessages() {

        if (!request::is_ajax()) {

            $error_messages = $this->getErrors();

            $messages = "<div id='" . $this->getName() . "_info' class='system_messagess' style='background-color:#FFD559; padding:7px; ";
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

        if (Factory::getApplication()->is_admin()) {
            $this->mode = 'admin';
        } else {

            $this->mode = 'front';
        }




        if (!$init) {
            session_start();
            if (isset($_SESSION['messages']['errors'][$this->mode]) && !empty($_SESSION['messages']['errors'][$this->mode])) {
                $this->sys_errors[$this->mode] = (array) array_merge((array) $_SESSION['messages']['errors'][$this->mode], (array) $this->sys_errors[$this->mode]);
            }
            if (isset($_SESSION['messages']['messages'][$this->mode]) && !empty($_SESSION['messages']['messages'][$this->mode])) {
                $this->sys_messages[$this->mode] = (array) array_merge((array) $_SESSION['messages']['messages'][$this->mode], (array) $this->sys_messages[$this->mode]);
            }

            if (isset($_SESSION['messages']['custom_messages'][$this->mode]) && !empty($_SESSION['messages']['custom_messages'][$this->mode])) {
                $this->contex_errors[$this->mode] = (array) array_merge((array) $_SESSION['messages']['custom_messages'][$this->mode], (array) $this->contex_errors[$this->mode]);
            }

			if(Factory::getApplication()->is_admin()){
				add_action('admin_notices', array($this, 'showMessages'));
			}
		}
        $init = true;

        $this->name = strtolower(get_class($this));

        $path = '';
        $root_path = '';
        $mode = $this->mode;

        $components = factory::getApplication()->get_active_components();

        foreach ((array) $components as $cpath => $class) {
            if ($class == get_class($this)) {
                $path = $cpath . "/" . $mode;
                $root_path = $cpath;
            }
        }

        $this->root_path = $root_path;

        $plugin_dir_name = plugin_basename($root_path);
        $this->rel_path = $plugin_dir_name;



        if (is_dir($root_path)) {
            $this->componentBase = $path;
            $this->component_url = WP_PLUGIN_URL . '/' . $plugin_dir_name;
            add_action("init", array($this, "loadLanguage"));
        } else {
            throw new Exception("cant determine component directory for component:" . get_class($this));
        }
    }

    public function loadLanguage() {

        $locale = apply_filters('plugin_locale', get_locale(), "com_" . $this->getName());

        load_textdomain("com_" . $this->getName(), WP_LANG_DIR . "/com_" . $this->getName() . "/com_" . $this->getName() . "-" . $locale . ".mo");

        load_plugin_textdomain("com_" . $this->getName(), false, $this->rel_path . "/languages");
    }

    public function getAssetsPath() {


        return realpath(str_replace('\\', '/', $this->root_path . DS . ".." . DS . "assets"));
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

    static public function getWPPages($com = null) {

        wp_cache_flush();
        if (!$com) {
            return array();
        }

        $result = wp_cache_get('getWPPages', strtolower("com_" . $com));

        if ($result !== false) {
            return $result;
        }

        $params = Factory::getComponent($com)->getParams();

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

        $cache = array();
        if (!empty($path)) {
            $cache[] = implode('/', array_reverse($path));
        }

        if (!array_key_exists(0, $cache)) {
            $cache[] = $com;
        }

        wp_cache_set('getWPPages', $cache, strtolower("com_" . $com));


        return $cache;
    }

}
