<?php

defined('_VALID_EXEC') or die('access denied');

final class Framework {

    private $output = null;
    private $method = 'default';
    private $mode = 'front';
    private $template = null;
    private $content = '';
    private $messages = '';
    private static $is_admin = null;

    public static function is_admin() {

        if (self::$is_admin === null) {
            return is_admin();
        }
        return self::$is_admin;
    }

    public function set_output(view $o, $m) {
        $this->output = $o;
        $this->method = $m;
    }

    static public function getInstance() {

        static $instance = null;

        if (!is_object($instance))
            $instance = new self();
        return $instance;
    }

    /*
     * Run the register_component filter and allow all plugins that are actual 
     * components of the framework to register themselves
     */

    public function get_active_components() {


        $components = (array) apply_filters("register_component", $components);


        foreach ((array) $components as $k => $com) {

            if (!class_exists($com) || !is_subclass_of((string) $com, 'component') || !is_dir($k) || !is_file($k . "/" . strtolower($com) . ".php")) {
                unset($components[$k]);
            }
        }


        return $components;
    }

    /**
     * Load components and give them chance to use wp actions and filters. 
     * Basically every component will be instantiated and after that, 
     * the init method will be executed (if presented)  
     *   
     */
    private function __construct() {

        if (defined('DOING_AJAX')) {
            if (Request::getVar('action', null) == 'framework-ajax-front') {
                self::$is_admin = false;
            } else if (Request::getVar('action', null) == 'framework-ajax-admin') {
                self::$is_admin = true;
            }
        }

        if (self::$is_admin === null) {
            if (is_admin()) {
                self::$is_admin = true;
            } else {
                self::$is_admin = false;
            }
        }
    }

    public function load_active_components() {
        $components = $this->get_active_components();

        foreach ((array) $components as $k => $v) {

            Factory::getApplication($v)->init();
        }
    }

    private function __clone() {
        
    }

    private function __sleep() {
        
    }

    /**
     * Executes the main method of the given component
     * 
     * @param type $component 
     */
    public function run($component) {
        $app = Factory::getApplication($component);
        do_action("before_component_run");
        do_action("before_run_" . $app->getName());
        $app->main();
    }

    /**
     *  Handles frontend ajax request.
     *  
     */
    public function handle_ajax_front() {
        if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
            header_remove('Content-Type');
        }
        request::ajaxMode();
        $this->run(request::getCmd('component'));
        die();
    }

    /**
     * Handle backend ajax requests
     */
    public function handle_ajax_admin() {


        if (!current_user_can('manage_options'))
            if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
                header_remove('Content-Type');
            }
        request::ajaxMode();

        $this->run(Request::getCmd('component'));
    }

    /**
     * Add global ajax handlers.
     * Give the components chance to examine the request and alter it.
     * Buffer the view output.
     * Alter the query loading the component page template and loading the view content in it.
     * 
     */
    public function initialize() {

        if (defined('DOING_AJAX')) {
            add_action('wp_ajax_nopriv_framework-ajax-front', array($this, 'handle_ajax_front'));
            add_action('wp_ajax_framework-ajax-front', array($this, 'handle_ajax_front'));

            add_action('wp_ajax_nopriv_framework-ajax-admin', array($this, 'handle_ajax_admin'));
            add_action('wp_ajax_framework-ajax-admin', array($this, 'handle_ajax_admin'));
            return;
        } else if (!Framework::is_admin()) {
            add_filter('request', array($this, 'component_dispatch'), 0);
            add_action('wp', array($this, 'view_dispatch'));
            add_filter('template_include', array($this, 'output_content'));
        } else {
            add_action('admin_menu', array($this, 'buildAdmin'));
        }
    }

    /**
     * Buffer component output for later use
     *  
     */
    public function view_dispatch() {

        if (!$this->output instanceof view)
            return '';
        ob_start();
        $this->output->{$this->method}();
        $this->content = ob_get_clean();
    }

    /**
     * Run component based on the current request.
     * The components have to inspect the request on their own. 
     * The components may alter the request.
     * 
     * @param type $request
     * @return type 
     */
    public function component_dispatch($request) {

        request::parse_request($request);


        if (request::get_wp_original_query()->is_page()) {
            request::get_wp_original_query()->get_posts();
        }
        //give the components chance to inspect or alter the request
        do_action('component_parse_request');

        try {
            //run the matched component that should handle the request if any.

            if (request::getCmd('component', null)) {
                $this->run(request::getCmd('component', null));
            }
        } catch (not_found_404 $e) {

            status_header('404');
            request::setVar('component', null);
            $this->template = get_404_template();
        }

        return (array) request::get_wp_request();
    }

    /**
     * Just befor the template is included, alter the wp_query
     * to load the current component page and inject the buffered component
     * output via "the_content" filter. Page templates can be used also.
     * 
     * 
     * @param string $template
     * @return string 
     */
    public function output_content($template) {

        if (request::getCmd('component', null)) {

            if ($this->template) {
                return $this->template;
            }

            if (file_exists(get_stylesheet_directory() . "/" . strtolower(request::getCmd('component')) . ".php")) {

                return get_stylesheet_directory() . "/" . strtolower(request::getCmd('component')) . ".php";
            }

            $rows = component::get_wp_pages(request::getCmd('component'));
            $page = $rows[0];
            $page = get_page_by_path($page);
            $tpl = get_post_meta((int) $page->ID, '_wp_page_template', true);

            $query = new wp_query("page_id=" . $page->ID);
            if (!$query->have_posts()) {
                return $template;
            }

            $page->comment_status = "closed";
            $page->post_title = "";

            $query->posts = array($page);
            $query->post = $page;
            $conditions = array();
            $conditions = apply_filters(strtolower(request::getCmd('component') . "_set_conditions"), $conditions);

            if (!empty($conditions)) {
                $query->init_query_flags();
                foreach ((array) $conditions as $k => $v) {

                    if (property_exists($query, $k) && substr($k, 0, 3) == "is_" && is_bool($v)) {

                        $query->{$k} = (bool) $v;
                    }
                }
            }

      
            
            
            $GLOBALS['query_string'] = "page_id=" . $page->ID;
            $GLOBALS['wp_the_query'] = clone $GLOBALS['wp_query'];
            $GLOBALS['wp_query'] = $query;
            $GLOBALS['post'] = $page;
                

            add_filter("the_content", array($this, 'attachTheContent'));
            remove_action('wp_head', 'noindex', 1);
            remove_action( "wp_head",'rel_canonical' );



            return locate_template(array($tpl, 'page.php'));
        } else {
            return $template;
        }
    }

    /**
     * Return or echoes the buffered component output
     *  
     * @return string 
     */
    public function attachTheContent() {

        if (self::is_admin()) {
            echo $this->content;
        } else {

            $app = Factory::getApplication(Request::getCMD('component'));

            $error_messages = $app->getErrors();

            $this->messages = "<div id='" . $app->getName() . "_info' style='";
            $this->messages .= (!$app->messagesCount() && !$app->errorsCount()) ? "display:none;" : "";
            $this->messages .= "' >";

            if ($app->errorsCount()) {


                foreach ((array) $error_messages as $msg) {

                    $this->messages .= "<div class='system_message_error'>" . strings::cleanText($msg) . "</div>";
                }
            }


            $sys_messages = $app->getMessages();


            if ($app->messagesCount()) {


                foreach ((array) $sys_messages as $msg) {

                    $this->messages .= "<div class='system_message_info'>" . strings::cleanText($msg) . "</div>";
                }
            }
            $this->messages .= "</div>";

            $app->clearMessages();

			
            return "<div id='com-" . strtolower(request::getCmd('component')) . "'>" . $this->messages . $this->content . "</div>";
        }
    }

    /**
     * Give the components chance to build admin menu.
     * Run the requested component in admin area.
     *   
     */
    public function buildAdmin() {

        if (isset($_GET['page'])) {
            $page = (string) $_GET['page'];
            $com = null;

            if (preg_match("/component_com_[a-z]+/i", $page)) {
                $com = Request::getCmd('page', null);
                $com = explode('-', $com);
                if (isset($com[1])) {
                    Request::setVar('con', $com[1]);
                }
                if (isset($com[2]) && !request::getCmd('task', null)) {
                    request::setVar('task', $com[2]);
                }
                $this->run(str_replace('component_com_', '', $com[0]));

                $this->view_dispatch();
            }
        }
        do_action('framework_admin_pages');
    }

}