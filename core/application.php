<?php

defined('_VALID_EXEC') or die('access denied');

final class Application {

    private $output = null;
    private $method = 'default';
    private $mode = 'front';
    private $template = null;
    private $content = '';
    private $messages = '';
    private $is_admin = null;
    protected $input = null;
    protected $input_override = null;

    public function getInput() {

        if ($this->input_override && $this->input_override instanceof Input) {

            return $this->input_override;
        }
        return $this->input;
    }

    public function setInput(Input $i = null) {
        $this->input_override = $i;
    }

    public function is_admin() {

        if ($this->is_admin === null) {
            return is_admin();
        }
        return $this->is_admin;
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

        $components = array();
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

        $this->input = new Input();



        if (defined('DOING_AJAX')) {
            if ($this->input->get('action', null) == 'ajax-call-front') {
                $this->is_admin = false;
            } else if ($this->input->get('action', null) == 'ajax-call-admin') {
                $this->is_admin = true;
            }
        }

        if ($this->is_admin === null) {
            if (is_admin()) {
                $this->is_admin = true;
            } else {
                $this->is_admin = false;
            }
        }
    }

    public function load_active_components() {
        $components = null;
        $components = $this->get_active_components();


        foreach ((array) $components as $k => $v) {

            Factory::getComponent($v)->init();
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
        $app = Factory::getComponent($component);
        do_action("before_component_run");
        do_action("before_run_" . $app->getName());



        $app->main();
    }

    /**
     *  Handles frontend ajax request.
     *  
     */
    public function handle_ajax_front() {

        request::ajaxMode();
        $this->run($this->input->get('component'));
        die();
    }

    /**
     * Handle backend ajax requests
     */
    public function handle_ajax_admin() {

        if (!current_user_can('manage_options')) {
            die("0");
        }


        request::ajaxMode();
        $this->run($this->input->get('component'));
        die();
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
            add_action('wp_ajax_nopriv_ajax-call-front', array($this, 'handle_ajax_front'));
            add_action('wp_ajax_ajax-call-front', array($this, 'handle_ajax_front'));

            add_action('wp_ajax_nopriv_ajax-call-admin', array($this, 'handle_ajax_admin'));
            add_action('wp_ajax_ajax-call-admin', array($this, 'handle_ajax_admin'));
            return;
        } else if (!$this->is_admin()) {

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
        $this->content = ob_get_contents();
		ob_end_clean();
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

            if ($this->input->get('component', null, "CMD")) {
                $this->run($this->input->get('component', null, "CMD"));
            }
        } catch (not_found_404 $e) {

            status_header('404');
            $this->input->set('component', null);
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

        if ($this->input->get('component', null, "cmd")) {

            if ($this->template) {

                return $this->template;
            }



            $rows = component::getWPPages($this->input->get('component', null, "CMD"));
            $page = $rows[0];
            $page = get_page_by_path($page);
            $tpl = get_post_meta((int) $page->ID, '_wp_page_template', true);


            $query = new wp_query("page_id=" . $page->ID);
            if (!$query->have_posts()) {
                return $template;
            }

            $page->comment_status = "closed";
            //$page->post_title = "";

            $query->posts = array($page);
            $query->post = $page;
            $conditions = array();
            $conditions = apply_filters(strtolower($this->input->get('component', null, "CMD") . "_set_conditions"), $conditions);

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

            if (file_exists(get_stylesheet_directory() . "/com_" . strtolower($this->input->get('component', null, "CMD")) . ".php")) {
                return get_stylesheet_directory() . "/com_" . strtolower($this->input->get('component', null, "CMD")) . ".php";
            }

            add_shortcode("framework", array($this, 'attachTheContent'));
            //add_filter("the_content", array($this, 'attachTheContent'));
            remove_action('wp_head', 'noindex', 1);
            remove_action("wp_head", 'rel_canonical');



            $tmp = null;
            foreach ((array) array($tpl, 'page.php') as $template_name) {
                if (!$template_name)
                    continue;

                if (file_exists(STYLESHEETPATH . '/' . $template_name)) {
                    $tmp = STYLESHEETPATH . '/' . $template_name;
                    break;
                }
                if (file_exists(dirname(realpath($template)) . '/' . $template_name)) {
                    $tmp = dirname(realpath($template)) . '/' . $template_name;
                    break;
                } else if (file_exists(TEMPLATEPATH . '/' . $template_name)) {
                    $tmp = TEMPLATEPATH . '/' . $template_name;
                    break;
                }
            }

            return empty($tmp) ? $template : $tmp;
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

        if ($this->is_admin()) {
            echo $this->content;
        } else {

            $app = Factory::getComponent($this->input->get('component', null, "CMD"));

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


            return "<div id='com-" . strtolower($this->input->get('component')) . "' class='com-" . strtolower($this->input->get('component')) . " addBootstrap'>" . $this->messages . $this->content . "</div>";
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
                $com = $this->input->get('page', null);
                $com = explode('-', $com);
                if (isset($com[1])) {
                    $this->input->set('con', $com[1]);
                }
                if (isset($com[2]) && !$this->input->get('task', null, "CMD")) {
                    $this->input->set('task', $com[2]);
                }
                $this->run(str_replace('component_com_', '', $com[0]));

				 $this->view_dispatch();
					
					
					
				$tmp = $this->content;
				$this->content = "";
				
				if (!Request::is_internal() && !Request::is_ajax()) {
					$this->content = "<div class='wrap'>";
					
					$bar = Toolbar::getInstance('toolbar');
					
					
					
					$this->content .= $bar->render();
				}
				
				$this->content .= $tmp;
				
				if (!Request::is_internal() && !Request::is_ajax()) {
					$this->content .= "</div>";
				}
            }
        }
        do_action('application_admin_pages');
    }

}
