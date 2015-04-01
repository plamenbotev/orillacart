<?php

class shop extends component {
    /*
     * Called after the constructor
     */

    public function init() {

        require_once(dirname(__FILE__) . "/action_handlers.php");

        require_once(realpath(dirname(__FILE__) . DS . 'helpers' . DS . 'mailer.php'));

        //init the mailer 
        mailer::init();
        //bind all action/filter handlers 
        orillacart_actions::init();

        //load shipping api
        require_once(realpath(dirname(__FILE__) . DS . 'methods' . DS . 'standart_shipping.php'));
        add_filter('register_shipping_class', 'standart_shipping::register');
        //load payments api
        require_once(realpath(dirname(__FILE__) . DS . 'methods' . DS . 'payment_method.php'));

        //load default widgets

        $widgets = glob(dirname(__FILE__) . DS . "widgets/*.php");

        foreach ((array) $widgets as $widget)
            require_once $widget;

        //load built in shipping and payment methods

        $widgets = glob(dirname(__FILE__) . DS . "methods/*.php");

        foreach ((array) $widgets as $widget)
            require_once $widget;
        //load fields generator helper
        require_once(realpath(dirname(__FILE__) . DS . 'helpers' . DS . 'fields.php'));
        //load sef router (parse sef links and makes non-sef links pretty)
        require_once(dirname(__FILE__) . DS . "router.php");

        //handle shortcodes
        if (!is_admin()) {
            //show products marked as special
            add_shortcode('special_products', array($this, 'special_products'));
            //show products marked as on sale
            add_shortcode('on_sale_products', array($this, 'on_sale_products'));
            //show last added products
            add_shortcode('recent_products', array($this, 'recent_products'));
            //show single product thumb
            //add_shortcode( 'product_thumb', array($this,'product_thumb') );
            //show product gallery
            // add_shortcode( 'product_gallery', array($this,'product_gallery') );
            //show product add to cart button
            //add_shortcode( 'product_add_to_cart', array($this,'product_add_to_cart') );
            //check if there is shortcode in some of the posts and allow the view to load
            //scripts and styles in the html head 
            add_filter('the_posts', array($this, 'shortcode_styles_and_scripts'));

            do_action('com_shop_shortcodes');
        }
    }

    public static function on_activate() {
        require_once(dirname(__FILE__) . "/installer.php");

        $installer = new shop_installer();

        $installer->activate();
    }

    public static function on_deactivate() {
        require_once(dirname(__FILE__) . "/installer.php");

        $installer = new shop_installer();

        $installer->deactivate();
    }

    public static function uninstall() {
        require_once(dirname(__FILE__) . "/installer.php");

        $installer = new shop_installer();

        $installer->uninstall();
    }

    public function __construct() {
        parent::__construct();

        //register models, helpers, tables, views and controllers paths in FILO list.
        //That list can be altered via plugins and new MVC triads can be added
        do_action("before_constructor_shop");
        Model::addIncludePath($this->getName(), $this->getComponentPath() . DS . "models");
        Helper::addIncludePath($this->getName(), $this->getComponentPath() . DS . ".." . DS . "helpers");
        View::addIncludePath($this->getName(), $this->getComponentPath() . DS . "views");
        Table::addIncludePath($this->getName(), $this->getComponentPath() . DS . ".." . DS . "tables");
        Controller::addIncludePath($this->getName(), $this->getComponentPath() . DS . "controllers");
        do_action("after_constructor_shop");
    }

    public function generate_canonical_tag() {

        $canonical = "";

        $input = Factory::getApplication()->getInput();

        switch ($input->get("con", null, "CMD")) {

            case "product_list":

                if ($input->get("cid", 0, "INT") == 0 && $input->get("task", null, "CMD") == null) {

                    $canonical = get_post_type_archive_link('product');
                } else {

                    if ($input->get("product_brand", null, "WORD")) {
                        $canonical = get_term_link($input->get("product_brand", null, "WORD"), "product_brand");
                    } else if ($input->get("product_tags", null, "WORD")) {
                        $canonical = get_term_link($input->get("product_tags", null, "WORD"), "product_tags");
                    } else if ($input->get("product_type", null, "WORD")) {
                        $canonical = get_term_link($input->get("product_type", null, "WORD"), "product_type");
                    } else {

                        $canonical = get_term_link((int) $input->get("cid", 0, "INT"), "product_cat");
                    }
                }
                if ($canonical && $input->get('paged', 0, "INT") > 1) {
                    global $wp_rewrite;
                    if (!$wp_rewrite->using_permalinks()) {
                        $canonical = add_query_arg('paged', $input->get('paged', 0, "INT"), $canonical);
                    } else {
                        if (is_front_page()) {
                            $base = $GLOBALS['wp_rewrite']->using_index_permalinks() ? 'index.php/' : '/';
                            $canonical = home_url($base);
                        }
                        $canonical = user_trailingslashit(trailingslashit($canonical) . trailingslashit($wp_rewrite->pagination_base) . $input->get('paged', 0, "INT"));
                    }
                }

                break;

            case "product":

                $canonical = get_permalink((int) $GLOBALS['wp_the_query']->get_queried_object_id());

                break;
        }

        $canonical = apply_filters('com_shop_canonical', $canonical);

        if ($canonical && !is_wp_error($canonical)) {

            echo '<link rel="canonical" href="' . esc_url($canonical, null, 'other') . '" />' . "\n";
        }
    }

    /*
     * Execute current request and prepare the output of the component
     *
     */

    public function main() {

        if (session_id() == "") {
            session_start();
        }

        $input = Factory::getApplication()->getInput();

        //add proper canonical tag      
        add_action("wp_head", array($this, "generate_canonical_tag"));
        add_action("wpseo_canonical", create_function("", "return'';"));

        $Head = Factory::getHead();

		$this->cron();
		
        if (Factory::getApplication()->is_admin()) {
			
		
			
            $Head->addscript('jsshopadminhelper', $this->getAssetsUrl() . '/js/jsshopadminhelper.js');
            $Head->addStyle('bootstrap', $this->getAssetsUrl() . '/bootstrap.css');
            $Head->addStyle('shop-icons', $this->getAssetsUrl() . '/icons.css');
            $Head->addStyle('bootstrap-buttons', $this->getAssetsUrl() . '/buttons.css');
            $Head->addStyle('shop-admin-styles', $this->getAssetsUrl() . '/shop-admin-styles.css');

            $Head->addCustomHeadTag('ajaxurl', "
                 <!--[if lt IE 9]>
                 <script src='" . $this->getAssetsUrl() . "/js/html5shiv.js'></script>
                 <script src='" . $this->getAssetsUrl() . "/js/respond.js'></script>
                 <![endif]-->
            ");
        } else {

           
            $Head->addscript('jquery');
            $Head->addscript('shop_helper', $this->getAssetsUrl() . '/js/jsshopfronthelper.js');
            $Head->addstyle('bootstrap', $this->getAssetsUrl() . '/bootstrap.css');
            $Head->addstyle('icons', $this->getAssetsUrl() . '/icons.css');
            $Head->addstyle('bootstrap-buttons', $this->getAssetsUrl() . '/buttons.css');
            $Head->addstyle('frontend-styles', $this->getAssetsUrl() . '/frontend-styles.css');

            $Head->addCustomHeadTag('ajaxurl', "
                <script type='text/javascript'>
                    jQuery(function() { 
                    window.shop_helper.ajaxurl = '" . admin_url('admin-ajax.php') . "';
                   
                    });
                </script>
                 <!--[if lt IE 9]>
                 <script src='" . $this->getAssetsUrl() . "/js/html5shiv.js'></script>
                 <script src='" . $this->getAssetsUrl() . "/js/respond.js'></script>
                 <![endif]-->



            ");
        }



        $this->getController($input->get('con', '', "CMD"))->execute($input->get('task', '', "CMD"));
    }
	
	
	protected function cron() {
        
		$db = Factory::getDBO();
		
		
		if(Factory::getApplication()->is_admin()){
			
			//admin cron tasks
			
		}else{
		
			

			$time = (int) ini_get('session.gc_maxlifetime');

			if (!$time){
				$time = 3600;
			}
			
			$time = (int) ( time() - $time );

			$db->setQuery("DELETE FROM #_shop_cart WHERE last_access < " . $time);

			if (session_id()) {
				$db->setQuery("UPDATE #_shop_cart SET last_access = '" . time() . "' WHERE session_id = '" . $db->secure(session_id()) . "' LIMIT 1");
			}
		}
	}
    //Shortcodes handlers begin from here

    /*
     * Show latest products
     */

    public function recent_products($atts, $content = null) {
        $params = shortcode_atts(array(
            'per_page' => '12',
            'columns' => '4',
            'orderby' => 'date',
            'order' => 'desc'
                ), $atts);

        $params = new Registry($params);


        $view = View::getInstance('shortcodes', 'shop');
        $view->assign('params', $params);
        $output = '';
        ob_start();
        $view->recent_products();
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }

    /*
     * Show products marked as special
     */

    public function special_products($atts, $content = null) {
        $params = shortcode_atts(array(
            'per_page' => '12',
            'columns' => '4',
            'orderby' => 'date',
            'order' => 'desc'
                ), $atts);

        $params = new Registry($params);


        $view = View::getInstance('shortcodes', 'shop');
        $view->assign('params', $params);
        $output = '';
        ob_start();
        $view->special_products();
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }

    /*
     * Show products marked on-sale
     */

    public function on_sale_products($atts, $content = null) {
        $params = shortcode_atts(array(
            'per_page' => '12',
            'columns' => '4',
            'orderby' => 'date',
            'order' => 'desc'
                ), $atts);

        $params = new Registry($params);


        $view = View::getInstance('shortcodes', 'shop');
        $view->assign('params', $params);
        $output = '';
        ob_start();
        $view->on_sale_products();
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }

    /*
     * Check if any shortcode is used and allow the view
     * to include head data 
     * 
     */

    public function shortcode_styles_and_scripts($posts) {

        if (empty($posts))
            return $posts;
        $used = array();
        foreach ($posts as $post) {
            if (stripos($post->post_content, '[special_products') !== false) {
                $used[] = 'special_products';
            }
            if (stripos($post->post_content, '[on_sale_products') !== false) {
                $used[] = 'on_sale_products';
            }
            if (stripos($post->post_content, '[recent_products') !== false) {
                $used[] = 'recent_products';
            }
            if (stripos($post->post_content, '[product_thumb') !== false) {
                $used[] = 'product_thumb';
            }
            if (stripos($post->post_content, '[product_gallery') !== false) {
                $used[] = 'product_gallery';
            }
            if (stripos($post->post_content, '[product_add_to_cart') !== false) {
                $used[] = 'product_add_to_cart';
            }
        }

        if (!empty($used)) {
            $view = View::getInstance('shortcodes', 'shop');
            $view->assign('shortcodes', $used);
            $view->shortcodes_head_data();
        }

        return $posts;
    }

    //Shortcodes handlers end here

    /*
     * Register that component in the framework
     * 
     * @param array $components
     * @return array
     */

    static public function register(array $components) {

        $components[dirname(__FILE__)] = __CLASS__;

        return $components;
    }

}

//Register that class as a new component, and allow the framework to initialize it properly
add_filter('register_component', 'shop::register');
