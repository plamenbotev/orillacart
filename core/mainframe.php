<?php

defined('_VALID_EXEC') or die('access denied');

final class mainframe {

    private $page_title = null;
    private $custom_tags = array();

    static public function isHome() {
        if (uri::parse()->get('base') == uri::parse()->get('current'))
            return true;
        return false;
    }

    static public function getInstance() {

        static $instance = null;

        if (!is_object($instance))
            $instance = new self;
        return $instance;
    }

    private function __construct() {

// register needed filters and actions to update styles and head tags

        if (Framework::is_admin()) {

            add_filter('admin_head', array($this, '_appendTags'));
        } else {

            add_filter('wp_head', array($this, '_appendTags'));
        }
        add_filter('wp_title', array($this, '_appendTags'));
		add_filter('wpseo_title',array($this, '_appendTags'));
        // add_filter('the_title',array($this,'_appendTags'));
    }

    private function __clone() {
        
    }

    private function __sleep() {
        
    }

    public function setPageTitle($title) {


        $this->page_title = strings::htmlentities($title);
    }

    public function _appendTags() {
        do_action("mainframe_before_add_head_tags",$this);
        $args = func_get_args();
        $now = current_filter();
        switch ($now) {
            case "wp_head":
            case"admin_head":

               
               echo implode("\n", $this->custom_tags);
                break;
            case "wp_title":
			case "wpseo_title":

                return $this->page_title;
                break;
            default:
                break;
        }
    }

    public function removeCustomHeadTag($id) {
        if (array_key_exists($id, $this->custom_tags)) {
            unset($this->custom_tags[$id]);
            return true;
        }
        return false;
    }

    public function addCustomHeadTag($id, $content) {

        if (array_key_exists($id, $this->custom_tags)) {
            return false;
        }
        $this->custom_tags[$id] = $content;
        return true;
    }

    public function addStyle($id, $file = null) {
        $path = get_stylesheet_directory();
        $url = get_stylesheet_directory_uri();
        if (file_exists($path . "/" . $id . ".css")) {
            wp_enqueue_style($id, $url . "/" . $id . ".css");
        } else {
            wp_enqueue_style($id, $file);
        }
    }

    public function addScript($id, $file = null, $dep = array(), $ver = false, $footer = false) {

        wp_enqueue_script($id, $file, $dep, $ver, $footer);
    }

    public function removeScript($id) {


        //to do
    }

    public function removeStyle($id) {

        //to do
    }

}