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
        require_once(realpath(dirname(__FILE__) . DS . 'helpers' . DS . 'standart_shipping.php'));
        add_filter('register_shipping_class', 'standart_shipping::register_method');
        //load payments api
        require_once(realpath(dirname(__FILE__) . DS . 'helpers' . DS . 'payment_method.php'));

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

        switch (request::getCmd("con")) {

            case "product_list":

                if (request::getInt("cid", 0) == 0 && request::getCmd("task", null) == null) {

                    $canonical = get_post_type_archive_link('product');
                } else {

                    if (request::getCmd("product_brand", null)) {
                        $canonical = get_term_link(request::getWord("product_brand"), "product_brand");
                    } else if (request::getCmd("product_tags", null)) {
                        $canonical = get_term_link(request::getWord("product_tags"), "product_tags");
                    } else if (request::getCmd("product_type", null)) {
                        $canonical = get_term_link(request::getWord("product_type"), "product_type");
                    } else {

                        $canonical = get_term_link((int) request::getInt("cid"), "product_cat");
                    }
                }
                if ($canonical && request::getInt('paged') > 1) {
                    global $wp_rewrite;
                    if (!$wp_rewrite->using_permalinks()) {
                        $canonical = add_query_arg('paged', request::getInt('paged'), $canonical);
                    } else {
                        if (is_front_page()) {
                            $base = $GLOBALS['wp_rewrite']->using_index_permalinks() ? 'index.php/' : '/';
                            $canonical = home_url($base);
                        }
                        $canonical = user_trailingslashit(trailingslashit($canonical) . trailingslashit($wp_rewrite->pagination_base) . request::getInt('paged'));
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

	if(session_id() == ""){
        session_start();
	}


        //add proper canonical tag      
        add_action("wp_head", array($this, "generate_canonical_tag"));
		add_action("wpseo_canonical",create_function("","return'';"));

        $mainframe = Factory::getMainframe();

        if (Framework::is_admin()) {

            $mainframe->addscript('jsshopadminhelper', $this->getAssetsUrl() . '/js/jsshopadminhelper.js');
			$mainframe->addStyle('bootstrap', $this->getAssetsUrl() . '/bootstrap.css');
            $mainframe->addStyle('shop-icons', $this->getAssetsUrl() . '/icons.css');
			$mainframe->addStyle('bootstrap-buttons', $this->getAssetsUrl() . '/buttons.css');
            $mainframe->addStyle('shop-admin-styles', $this->getAssetsUrl() . '/template.css');
            
            $mainframe->addCustomHeadTag('ajaxurl', "
                 <!--[if lt IE 9]>
                 <script src='".$this->getAssetsUrl()."/js/html5shiv.js'></script>
                 <script src='".$this->getAssetsUrl()."/js/respond.js'></script>
                 <![endif]-->
            ");
            
            
            
        } else {

            $this->getHelper('cart')->cron();
            $mainframe->addscript('jquery');
            $mainframe->addscript('shop_helper', $this->getAssetsUrl() . '/js/jsshopfronthelper.js');
			$mainframe->addstyle('bootstrap', $this->getAssetsUrl() . '/bootstrap.css');
			$mainframe->addstyle('icons', $this->getAssetsUrl() . '/icons.css');
			$mainframe->addstyle('bootstrap-buttons', $this->getAssetsUrl() . '/buttons.css');
            $mainframe->addstyle('frontend-styles', $this->getAssetsUrl() . '/frontend-styles.css');

            $mainframe->addCustomHeadTag('ajaxurl', "
                <script type='text/javascript'>
                    jQuery(function() { 
                    window.shop_helper.ajaxurl = '" . admin_url('admin-ajax.php') . "';
                   
                    });
                </script>
                 <!--[if lt IE 9]>
                 <script src='".$this->getAssetsUrl()."/js/html5shiv.js'></script>
                 <script src='".$this->getAssetsUrl()."/js/respond.js'></script>
                 <![endif]-->



            ");
        }

        $this->getController(Request::getCmd('con', ''))->execute(Request::getCmd('task'));
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

    static public function register_component($components) {

        $components[dirname(__FILE__)] = __CLASS__;

        return $components;
    }

}

//Register that class as a new component, and allow the framework to initialize it properly
add_filter('register_component', 'shop::register_component');