<?php

final class orillacart_actions {

    private static $instance = null;
    

    public static function init() {

        if (self::$instance instanceof self)
            return self::$instance;

        return self::$instance = new self();
    }

    private function __construct() {

        //add roles

        add_role('customer', 'Customer', array(
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false
        ));

        //register all image sizes from the shop params

        $app = Factory::getApplication('shop');
        $params = $app->getParams();

        add_image_size('product_medium', $params->get('mediumX'), $params->get('mediumY'));
        add_image_size('product_thumb', $params->get('thumbX'), $params->get('thumbY'));
        add_image_size('product_mini', $params->get('miniX'), $params->get('miniY'));
        add_image_size('cat_thumb', $params->get('catX'), $params->get('catY'));

        /* initialize all filters and actions */

        //edit media manager for downloadables
        add_filter('media_upload_tabs', array($this, 'edit_media_tabs'), 0);
        add_filter('media_upload_mime_type_links', array($this, 'edit_media_upload_links'));
        //remove all other than downloadables from media manager
        add_action('pre_get_posts', array($this, 'filter_media_attachments'));
        //convert query in the menu manager to sef link
        add_filter('wp_nav_menu_objects', array($this, 'sef_menu_links'));
        //change upload path for the downloadable attachments
        add_filter('upload_dir', array($this, 'change_upload_path'));
        //process upload for downloadable attachment
        add_action('media_upload_downloadable_product', array($this, 'ulpoad_downloadable_product'));
        //add digital term to all attachments that are ment for download
        add_action('add_attachment', array($this, 'download_term'));
        //add price field for attachment in media manager 
        add_filter("attachment_fields_to_edit", array($this, "add_price_field"), null, 2);
        //save price field for attachment
        add_filter('attachment_fields_to_save', array($this, "save_price_field"), null, 2);
        //adds additional query vars in fontend
        add_filter('query_vars', array($this, 'register_custom_query_vars'));
        //generate the rewrite rules
        add_filter('generate_rewrite_rules', array($this, 'register_custom_rewrite_rules'));
        //register all CPT and taxonomies
        add_action('init', array($this, 'register_types'));
        //starts download of file from order
        add_action('init', array($this, 'download_listener'));
        //add thumbnail support for current theme
        add_action('after_setup_theme', array($this, 'add_product_thumbnails'), 99);
        //register all admin pages
        add_action('framework_admin_pages', array($this, 'register_shop_admin_pages'));
        //add product metaboxes
        add_action('add_meta_boxes_product', array($this, 'register_product_boxes'));
        //alter the product form to allow uploads of files
        add_action('post_edit_form_tag', array($this, 'add_product_enctype'));
        //save all product metaboxes
        add_action('save_post', array($this, 'product_meta_update'));
        //analize the current request and loads the logic if required
        add_action('component_parse_request', array($this, 'route_request'));
        //filter products by term
        add_action('restrict_manage_posts', array($this, "admin_filter_products_by_term"));
        add_filter('parse_query', array($this, 'convert_id_to_term_in_query'));

        //set query conditional tags
        add_filter("shop_set_conditions", array($this, "set_conditional_tags"));

        //clear customer session if he is logged out from WP
        add_action('wp_logout', array($this, 'logout_customer'));
        //clear the user fields but keep the cart content
        add_action('wp_login', array($this, 'clear_customer_fields'));
        // add_action('delete_user', array($this, 'user_transaction'));
        //alter products columns from products list in admin
        add_filter('manage_edit-product_columns', array($this, 'add_new_products_columns'), 1);
        add_action('manage_product_posts_custom_column', array($this, 'manage_product_columns'), 10, 2);
        //alter order columns in order list in admin
        add_action('manage_edit-shop_order_columns', array($this, 'add_new_orders_columns'), 10, 2);
        add_action('manage_shop_order_posts_custom_column', array($this, 'manage_order_columns'), 10, 2);
        //transaction for when post is deleted clear all additional tables
        add_action('delete_post', array($this, 'delete_transaction'));
        //add order metaboxes
        add_action('add_meta_boxes_shop_order', array($this, 'register_order_boxes'));
        //update or store order meta
        add_action('save_post', array($this, 'order_meta_update'));
        //adds abbility for term meta
        add_action('init', array($this, 'init_term_meta_table'));
        //remove read more link in twenty eleven theme
        add_action("after_setup_theme", array($this, "remove_read_more"));
        //enque styles and scripts for internal WP screens that loads the framework 
        //after the needed action to add those styles and scripts from the view or the controller
        add_action("admin_enqueue_scripts", array($this, 'add_admin_head_data'));
        //clear the guest session after user login
        add_action('wp_login', 'customer_clear_session');

        if (Framework::is_admin()) {

            //add type=downloadable_product to digital files upload form action url
            add_filter('media_upload_form_url', array($this, 'change_upload_form_type'), 10, 2);
            //add abbility to search the order by all meta values
            add_filter('parse_query', array($this, 'order_search_custom_fields'));
            add_filter('get_search_query', array($this, 'order_search_label'));
            //view all orders for specific customer
            add_filter('request', array($this, 'orders_by_customer_query'));
            //register admin query vars
            add_filter('query_vars', array($this, 'add_custom_query_var_admin'));
            //filter orders by order_status term
            add_action('restrict_manage_posts', array($this, "admin_filter_orders_by_status"));
            //add additional columns to user list in WP admin
            add_filter('manage_users_columns', array($this, 'manage_user_columns'), 10, 1);
            add_action('manage_users_custom_column', array($this, 'manage_user_column_values'), 10, 3);
            //add additional meta fields to user edit form
            add_action('show_user_profile', array($this, 'customer_meta_fields'));
            add_action('edit_user_profile', array($this, 'customer_meta_fields'));
            //save additional user meta data
            add_action('personal_options_update', array($this, 'customer_meta_save'));
            add_action('edit_user_profile_update', array($this, 'customer_meta_save'));
            add_action('component_activate', array($this, 'activate'));
            add_action('component_deactivate', array($this, 'deactivate'));
            add_action('component_uninstall', array($this, 'uninstall'));
            add_action('admin_notices', array($this, 'update_database'));

            add_filter('wp_insert_post_data', array($this, 'pre_product_save'), '99', 2);
        }
    }

    public function change_upload_form_type($url, $type) {
        if (!isset($_REQUEST['type']) || $_REQUEST['type'] != 'downloadable_product') {
            return $url;
        }

        return add_query_arg('type', 'downloadable_product', $url);
    }

    public function pre_product_save($data, $raw) {
        global $typenow;
        if ($typenow == 'product') {
            if (isset($data['post_parent']))
                unset($data['post_parent']);
        }


        return $data;
    }

    public function update_database() {

        $params = Factory::getApplication('shop')->getParams();


        if (is_admin() && version_compare($params->get('db_version'), $params->get('db_version', true), '<')) {

            if (isset($_GET['com_shop_update_db']) && (bool) $_GET['com_shop_update_db']) {
                require_once(dirname(__FILE__) . "/installer.php");

                $installer = new shop_installer();

                $response = $installer->update_db();
            } else {

                // Display upgrade nag
                echo '
				<div class="update-com-shop">
					' . sprintf(__('Your database needs an update. Please <strong>backup</strong> &amp; %s.', 'com_shop'), '<a href="' . add_query_arg('com_shop_update_db', 'true') . '">' . __('update now', 'com_shop') . '</a>') . '
				</div>
			';
            }
        }
    }

    public function admin_filter_orders_by_status() {
        global $typenow, $wp_query;
        if ($typenow == 'shop_order') :
            $terms = get_terms('order_status', array('hide_empty' => false));
            $output = "<select name='order_status' id='dropdown_shop_order_status'>";
            $output .= '<option value="">' . __('Show all statuses', 'com_shop') . '</option>';
            foreach ($terms as $term) :
                $output .="<option value='$term->slug' ";
                if (isset($wp_query->query['order_status']))
                    $output .=selected($term->slug, $wp_query->query['order_status'], false);
                $output .=">" . __($term->name, 'com_shop') . " ($term->count)</option>";
            endforeach;
            $output .="</select>";
            echo $output;
        endif;
    }

    public function add_admin_head_data($a) {

        $screen = get_current_screen();
        switch ($screen->id) {
            case "edit-shop_order":
                request::execute("component=shop&con=orders");
                break;
            case "edit-product":

                request::execute("component=shop&con=products");
                break;
            case "edit-product_brand":

                $GLOBALS['parent_file'] = 'component_com_shop-orders';
                $GLOBALS['submenu_file'] = 'edit-tags.php?taxonomy=product_brand&post_type=product';
                break;
            case "edit-product_tags":
                $GLOBALS['parent_file'] = 'component_com_shop-orders';
                $GLOBALS['submenu_file'] = 'edit-tags.php?taxonomy=product_tags&post_type=product';

                break;
            case "product":
                $GLOBALS['parent_file'] = 'component_com_shop-orders';
                $GLOBALS['submenu_file'] = 'edit.php?post_type=product';

                break;
            case "edit-product_cat":
                $GLOBALS['parent_file'] = 'component_com_shop-orders';
                $GLOBALS['submenu_file'] = 'edit-tags.php?taxonomy=product_cat&post_type=product';

                break;
            case "edit-shipping_group":
                $GLOBALS['parent_file'] = 'component_com_shop-orders';
                $GLOBALS['submenu_file'] = 'edit-tags.php?taxonomy=shipping_group&post_type=product';

                break;
        }
        //  die($screen->id);
    }

    public function edit_media_tabs($tabs) {
        if (!isset($_REQUEST['type']) || $_REQUEST['type'] != 'downloadable_product')
            return $tabs;
        remove_filter('media_upload_tabs', 'update_gallery_tab');
        return $tabs;
    }

    public function edit_media_upload_links($links) {
        if (!isset($_REQUEST['type']) || $_REQUEST['type'] != 'downloadable_product')
            return $links;

        foreach ((array) $links as $k => $v) {

            $links[$k] = preg_replace("#<span.*</span>#", '', $v);
        }

        return $links;
    }

    public function filter_media_attachments($query) {
        if (isset($_REQUEST['type']) && $_REQUEST['type'] == 'downloadable_product') {
            $query->set('taxonomy', 'product_type');
            $query->set('term', 'digital');
        } else if (strtolower(basename($_SERVER['SCRIPT_NAME'])) == 'media-upload.php') {



            $tax = array(
                array(
                    'taxonomy' => 'product_type',
                    'terms' => array('digital'),
                    'field' => 'slug',
                    'operator' => 'NOT IN'
                )
            );


            $tax_query = $query->get('tax_query');
            if (!empty($tax_query)) {
                $tax = array(
                    'relation' => 'AND',
                    $tax_query,
                    $tax
                );
            }


            $query->set('tax_query', $tax);
        }

        return $query;
    }

    public function sef_menu_links($items) {
        foreach ((array) $items as $item) {

            $url = str_replace(array('http://', 'https://'), '', strings::html_entity_decode($item->url));

            if (stripos($url, 'component=shop') !== false) {

                $item->url = Route::get($url);
            }
        }
        return $items;
    }

    public function change_upload_path($pathdata) {

        if (isset($_POST['type']) && $_POST['type'] == 'downloadable_product') {

            // Uploading a downloadable file
            $subdir = '/com_shop_uploads' . $pathdata['subdir'];
            $pathdata['path'] = str_replace($pathdata['subdir'], $subdir, $pathdata['path']);
            $pathdata['url'] = str_replace($pathdata['subdir'], $subdir, $pathdata['url']);
            $pathdata['subdir'] = str_replace($pathdata['subdir'], $subdir, $pathdata['subdir']);
            return $pathdata;
        }

        return $pathdata;
    }

    public function ulpoad_downloadable_product() {

        do_action('media_upload_file');
    }

    public function download_term($id) {
        if (isset($_POST['type']) && $_POST['type'] == 'downloadable_product') {

            wp_set_object_terms($id, 'digital', 'product_type', false);
        }
    }

    public function add_price_field($form_fields, $post) {
        // $form_fields is a special array of fields to include in the attachment form  
        // $post is the attachment record in the database  
        //     $post->post_type == 'attachment'  
        // (attachments are treated as posts in WordPress)  
        // add our custom field to the $form_fields array  
        // input type="text" name/id="attachments[$attachment->ID][custom1]"
        if (isset($form_fields['product_type']))
            unset($form_fields['product_type']);
        if (has_term('digital', 'product_type', $post)) {
            // if you will be adding error messages for your field,  
            // then in order to not overwrite them, as they are pre-attached  
            // to this array, you would need to set the field up like this:  
            $form_fields["price"]["label"] = __("File price", 'com_shop');
            $form_fields["price"]["input"] = "text";
            $form_fields["price"]["value"] = get_post_meta($post->ID, "_price", true);
        }
        return $form_fields;
    }

    public function save_price_field($post, $attachment) {
        // $attachment part of the form $_POST ($_POST[attachments][postID])  
        // $post attachments wp post array - will be saved after returned  
        //     $post['post_type'] == 'attachment'  

        if (isset($attachment['price'])) {
            // update_post_meta(postID, meta_key, meta_value);  
            update_post_meta($post['ID'], '_price', $attachment['price']);
        }
        return $post;
    }

    public function register_custom_query_vars($vars) {
        if (!Framework::is_admin()) {
            $vars[] = "con";
            $vars[] = "component";
            $vars[] = "task";
            $vars[] = "group";
            $vars[] = "id";
        }
        return $vars;
    }

    public function register_custom_rewrite_rules($wp_rewrite) {

        $pages = component::get_wp_pages('shop');

        $path = $pages[0];

        $new[$path . '/cart/add/([0-9]*)/?$'] = 'index.php?pagename=' . $path . '&component=shop&con=cart&task=add_to_cart&id=$matches[1]';
        $new[$path . '/cart/remove/([0-9]*)/?$'] = 'index.php?pagename=' . $path . '&component=shop&con=cart&task=remove&group=$matches[1]';
        $new[$path . '/cart/?$'] = 'index.php?pagename=' . $path . '&component=shop&con=cart';
        $new[$path . '/checkout/?$'] = 'index.php?pagename=' . $path . '&component=shop&con=cart&task=checkout';
        $new[$path . '/account/?$'] = 'index.php?pagename=' . $path . '&component=shop&con=account';
        $new[$path . '/account/page/([0-9]{1,})/?$'] = 'index.php?pagename=' . $path . '&component=shop&con=account&paged=$matches[1]';


        $wp_rewrite->rules = $new + $wp_rewrite->rules;
    }

    public function register_types() {


        //Get base slug from the page with the component shortcode
        $pages = component::get_wp_pages('shop');
        $base_slug = $pages[0];

        register_taxonomy('product_type', array('product', 'attachment'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => __('Product Types', 'com_shop'),
                'singular_name' => __('Product Type', 'com_shop'),
                'search_items' => __('Search Product Type', 'com_shop'),
                'all_items' => __('All Product Types', 'com_shop'),
                'parent_item' => __('Parent Product Type', 'com_shop'),
                'parent_item_colon' => __('Parent Product Type:', 'com_shop'),
                'edit_item' => __('Edit Product Type', 'com_shop'),
                'update_item' => __('Update Product Type', 'com_shop'),
                'add_new_item' => __('Add New Product Type', 'com_shop'),
                'new_item_name' => __('New Product Type Name', 'com_shop')
            ),
            'update_count_callback' => '_update_post_term_count',
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => true,
            'query_var' => true,
            'rewrite' => array('slug' => $base_slug . '/product-type', 'with_front' => false),
                )
        );


        register_taxonomy('product_cat', array('product'), array(
            'hierarchical' => true,
            'show_ui' => false,
            'show_in_nav_menus' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => $base_slug . '/category', 'with_front' => false),
                )
        );

        register_taxonomy('product_brand', array('product'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => __('Product Brands', 'com_shop'),
                'singular_name' => __('Product Brand', 'com_shop'),
                'search_items' => __('Search Products Brands', 'com_shop'),
                'all_items' => __('All Brands', 'com_shop'),
                'edit_item' => __('Edit Brand', 'com_shop'),
                'update_item' => __('Update Brand', 'com_shop'),
                'add_new_item' => __('Add New Brand', 'com_shop'),
                'new_item_name' => __('New Product Brand Name', 'com_shop')
            ),
            'show_ui' => false,
            'show_in_nav_menus' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => $base_slug . '/brand', 'with_front' => false),
                )
        );

        //group products in shipping group
        register_taxonomy('shipping_group', array('product'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => __('Shipping Group', 'com_shop'),
                'singular_name' => __('Shipping Group', 'com_shop'),
                'search_items' => __('Search Shipping Groups', 'com_shop'),
                'all_items' => __('All Groups', 'com_shop'),
                'edit_item' => __('Edit Group', 'com_shop'),
                'update_item' => __('Update Group', 'com_shop'),
                'add_new_item' => __('Add New Group', 'com_shop'),
                'new_item_name' => __('New Shipping Group Name', 'com_shop')
            ),
            'show_ui' => false,
            'show_in_nav_menus' => true,
            'show_in_menu' => true,
            'query_var' => is_admin() ? true : false,
            'rewrite' => false,
                )
        );

        //register product tags taxonomy
        register_taxonomy('product_tags', array('product'), array(
            'hierarchical' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_menu' => false,
            'query_var' => true,
            'rewrite' => array('slug' => $base_slug . '/tag', 'with_front' => false)
                )
        );




        register_post_type("product", array(
            'labels' => array(
                'name' => __('Products', 'com_shop'),
                'singular_name' => __('Product', 'com_shop'),
                'all_items' => __('All Products', 'com_shop'),
                'add_new' => __('Add Product', 'com_shop'),
                'add_new_item' => __('Add New Product', 'com_shop'),
                'edit' => __('Edit', 'com_shop'),
                'edit_item' => __('Edit Product', 'com_shop'),
                'new_item' => __('New Product', 'com_shop'),
                'view' => __('View Product', 'com_shop'),
                'view_item' => __('View Product', 'com_shop'),
                'search_items' => __('Search Products', 'com_shop'),
                'not_found' => __('No Products found', 'com_shop'),
                'not_found_in_trash' => __('No Products found in trash', 'com_shop'),
                'parent' => __('Parent Product', 'com_shop')
            ),
            'description' => __('This is where you can add new products to your store.', 'com_shop'),
            'public' => true,
            'show_ui' => true,
            'hierarchical' => true,
            'capability_type' => 'post',
            'publicly_queryable' => true,
            'exclude_from_search' => false,
            'hierarchical' => true,
            'rewrite' => array('slug' => $base_slug, 'with_front' => false),
            'query_var' => true,
            'supports' => array('title', 'editor', 'thumbnail', 'comments', 'excerpt'),
            'has_archive' => $base_slug,
            'show_in_menu' => false,
            'show_in_nav_menus' => true,
                //'_edit_link'=>'admin.php?page=component_com_shop&con=products&task=edit&id=%d'
                )
        );

        register_taxonomy('order_status', array('shop_order'), array(
            'hierarchical' => false,
            'update_count_callback' => '_update_post_term_count',
            'labels' => array(
                'name' => __('Order statuses', 'com_shop'),
                'singular_name' => __('Order status', 'com_shop'),
                'search_items' => __('Search Order statuses', 'com_shop'),
                'all_items' => __('All  Order statuses', 'com_shop'),
                'parent_item' => __('Parent Order status', 'com_shop'),
                'parent_item_colon' => __('Parent Order status:', 'com_shop'),
                'edit_item' => __('Edit Order status', 'com_shop'),
                'update_item' => __('Update Order status', 'com_shop'),
                'add_new_item' => __('Add New Order status', 'com_shop'),
                'new_item_name' => __('New Order status Name', 'com_shop')
            ),
            'show_ui' => false,
            'show_in_nav_menus' => false,
            'show_in_menu' => false,
            'query_var' => Framework::is_admin() ? true : false,
            'rewrite' => false
                )
        );





        register_post_type("shop_order", array(
            'labels' => array(
                'name' => __('Orders', 'com_shop'),
                'singular_name' => __('Order', 'com_shop'),
                'add_new' => __('Add Order', 'com_shop'),
                'add_new_item' => __('Add New Order', 'com_shop'),
                'edit' => __('Edit', 'com_shop'),
                'edit_item' => __('Edit Order', 'com_shop'),
                'new_item' => __('New Order', 'com_shop'),
                'view' => __('View Order', 'com_shop'),
                'view_item' => __('View Order', 'com_shop'),
                'search_items' => __('Search Orders', 'com_shop'),
                'not_found' => __('No Orders found', 'com_shop'),
                'not_found_in_trash' => __('No Orders found in trash', 'com_shop'),
                'parent' => __('Parent Orders', 'com_shop')
            ),
            'description' => __('This is where store orders are stored.', 'com_shop'),
            'public' => true,
            'show_ui' => true,
            'capability_type' => 'post',
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_in_menu' => 'component_com_shop-orders',
            'hierarchical' => false,
            'show_in_nav_menus' => false,
            'rewrite' => false,
            'query_var' => true,
            'supports' => array(null),
            'has_archive' => false
                )
        );
    }

    public function download_listener() {
        if (isset($_GET['order_key']) && isset($_GET['file'])) {
            Request::execute("component=shop&con=account&task=download&order_key=" . $_GET['order_key'] . "&file=" . (int) $_GET['file']);
        }
    }

    public function add_product_thumbnails() {
        if (!current_theme_supports('post-thumbnails')) {
            add_theme_support('post-thumbnails');
            remove_post_type_support('post', 'thumbnail');
            remove_post_type_support('page', 'thumbnail');
        } else {
            add_post_type_support('product', 'thumbnail');
        }
    }

    public function register_shop_admin_pages() {
        global $menu;
        $params = Factory::getApplication('shop')->getParams();

        add_menu_page('orillacart', __('My Shop', 'com_shop'), 'manage_options', 'component_com_shop-orders', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('List all products', 'com_shop'), __('List all products', 'com_shop'), 'manage_options', 'edit.php?post_type=product');
        add_submenu_page('component_com_shop-orders', __('Add new product', 'com_shop'), __('Add new Product', 'com_shop'), 'manage_options', 'post-new.php?post_type=product');
        add_submenu_page('component_com_shop-orders', __('Product tags', 'com_shop'), __('Product tags', 'com_shop'), 'manage_options', 'edit-tags.php?taxonomy=product_tags&post_type=product');
        add_submenu_page('component_com_shop-orders', __('Product categories', 'com_shop'), __('Product categories', 'com_shop'), 'manage_options', 'component_com_shop-category', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Product brands', 'com_shop'), __('Product brands', 'com_shop'), 'manage_options', 'edit-tags.php?taxonomy=product_brand&post_type=product');
        add_submenu_page('component_com_shop-orders', __('Stockrooms', 'com_shop'), __('Stockrooms', 'com_shop'), 'manage_options', 'component_com_shop-stockroom', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Manage Amounts', 'com_shop'), __('Manage Amounts', 'com_shop'), 'manage_options', 'component_com_shop-stockroom-manage', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Attribute Sets', 'com_shop'), __('Attribute Sets', 'com_shop'), 'manage_options', 'component_com_shop-attributes', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Countries', 'com_shop'), __('Countries', 'com_shop'), 'manage_options', 'component_com_shop-country', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Taxes', 'com_shop'), __('Taxes', 'com_shop'), 'manage_options', 'component_com_shop-tax', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Payment Methods', 'com_shop'), __('Payment Methods', 'com_shop'), 'manage_options', 'component_com_shop-payment', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Shipping Methods', 'com_shop'), __('Shipping Methods', 'com_shop'), 'manage_options', 'component_com_shop-shipping', array(Factory::getFramework(), 'attachTheContent'));
        add_submenu_page('component_com_shop-orders', __('Shipping Groups', 'com_shop'), __('Shipping Groups', 'com_shop'), 'manage_options', 'edit-tags.php?taxonomy=shipping_group&post_type=product');
        add_submenu_page('component_com_shop-orders', __('Configuration', 'com_shop'), __('Configuration', 'com_shop'), 'manage_options', 'component_com_shop-admin-configuration', array(Factory::getFramework(), 'attachTheContent'));
    }

    public function register_product_boxes() {
        static $done = false;

        if (!$done) {
            request::execute('component=shop&mode=admin&con=products&task=meta_boxes');
            $done = true;
        }
    }

    public function register_order_boxes() {
        static $done = false;

        if (!$done) {
            request::execute('component=shop&mode=admin&con=orders&task=meta_boxes');
            $done = true;
        }
    }

    public function add_product_enctype() {

        $post = $post_type = $post_type_object = null;
        $post_id = Request::getInt('post');
        if ($post_id)
            $post = get_post($post_id);

        if ($post) {
            $post_type = $post->post_type;
        }

        if (!$post_type) {

            $post_type = Request::getCmd('post_type', '');
        }

        if ('product' == $post_type) {
            echo " enctype='multipart/form-data' ";
        }
    }

    public function product_meta_update($id) {

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;

        if (get_post_type((int) $id) != 'product')
            return;

        if (request::getCmd('task', null) == 'create_variation') {
            request::execute('component=shop&mode=admin&con=products&task=save_variation&id=' . $id);
        } else {
            request::execute('component=shop&mode=admin&con=products&task=save&id=' . $id);
        }
    }

    public function order_meta_update($id) {

        static $saved = false;

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !Framework::is_admin())
            return;

        if (request::getCmd('task', '') == "save_product_to_order")
            return;

        if (get_post_type((int) $id) != 'shop_order')
            return;

        if (isset($_GET['action']) && in_array($_GET['action'], array('trash', 'untrash')))
            return;
        if ($saved)
            return;
        $saved = true;
        request::execute('component=shop&mode=admin&con=orders&task=save&id=' . $id);
    }

    public function set_conditional_tags($tags) {
            
        return array();
       
    }

    public function route_request() {

        $q = request::get_wp_original_query();

        $o = $q->get_queried_object();

        if (!Framework::is_admin()) {

            if ($q->is_tax('product_cat') || $q->is_tax('product_brand') || $q->is_tax('product_tags') || $q->is_tax("product_type")) {


                request::setVar('component', 'shop');
                request::setVar('con', 'product_list');

                if ($q->is_tax('product_brand') || $q->is_tax('product_tags') || $q->is_tax('product_type')) {
                    request::setVar('task', 'brand');
                } else {

                    request::setVar('cid', (int) $o->term_id);
                }
            } else if ($q->is_post_type_archive('product')) {

                request::setVar('component', 'shop');
                request::setVar('con', 'product_list');
            } else if ($q->is_single() && $q->get('post_type') == 'product') {


                request::setVar('component', 'shop');
                request::setVar('con', 'product');
            } else {

                $rows = component::get_wp_pages('shop');
                $page = $rows[0];

                $page = get_page_by_path($page);

                if (is_object($page) && $q->is_page($page->ID)) {

                    request::set_wp_request(array('post_type' => 'product'));
                    request::setVar('component', 'shop');
                    if ($q->get('con')) {
                        request::setVar('con', $q->get('con'));
                    } else {
                        request::setVar('con', 'product_list');
                    }

                    if ($q->get('task')) {
                        request::setVar('task', $q->get('task'));
                    }

                    if (request::getCmd('con', null) == 'cart' && request::getCmd('task', null) == 'remove') {
                        request::setVar('group', (int) $q->get('group'));
                    } else if (request::getCmd('con', null) == 'cart' && request::getCmd('task', null) == 'add_to_cart') {

                        request::setVar('id', (int) $q->get('id'));
                    }
                }
            }
        }
    }

    public function admin_filter_products_by_term() {
        global $typenow;
        $post_type = 'product';
        $taxonomy = 'product_cat';

        if ($typenow == $post_type) {
            $selected = isset($_GET[$taxonomy]) ? $_GET[$taxonomy] : '';
            $info_taxonomy = get_taxonomy($taxonomy);
            wp_dropdown_categories(array(
                'show_option_all' => __("Show All {$info_taxonomy->label}", 'com_shop'),
                'taxonomy' => $taxonomy,
                'name' => $taxonomy,
                'orderby' => 'name',
                'selected' => $selected,
                'show_count' => true,
                'hide_empty' => true,
            ));
        }

        $taxonomy = 'product_brand';
        if ($typenow == $post_type) {
            $selected = isset($_GET[$taxonomy]) ? $_GET[$taxonomy] : '';
            $info_taxonomy = get_taxonomy($taxonomy);
            wp_dropdown_categories(array(
                'show_option_all' => __("Show All {$info_taxonomy->label}", 'com_shop'),
                'taxonomy' => $taxonomy,
                'name' => $taxonomy,
                'orderby' => 'name',
                'selected' => $selected,
                'show_count' => true,
                'hide_empty' => true,
            ));
        }


        $taxonomy = 'product_type';
        if ($typenow == $post_type) {
            $selected = isset($_GET[$taxonomy]) ? $_GET[$taxonomy] : '';
            $info_taxonomy = get_taxonomy($taxonomy);
            wp_dropdown_categories(array(
                'show_option_all' => __("Show All {$info_taxonomy->label}", 'com_shop'),
                'taxonomy' => $taxonomy,
                'name' => $taxonomy,
                'orderby' => 'name',
                'selected' => $selected,
                'show_count' => true,
                'hide_empty' => true,
            ));
        }
    }

    public function convert_id_to_term_in_query($query) {
        global $pagenow;
        $post_type = 'product';

        $q_vars = &$query->query_vars;
        if ($pagenow == 'edit.php' && isset($q_vars['post_type']) && $q_vars['post_type'] == $post_type && isset($q_vars['product_cat']) && is_numeric($q_vars['product_cat']) && $q_vars['product_cat'] != 0) {
            $term = get_term_by('id', $q_vars['product_cat'], 'product_cat');
            $q_vars['product_cat'] = $term->slug;
        }
        if ($pagenow == 'edit.php' && isset($q_vars['post_type']) && $q_vars['post_type'] == $post_type && isset($q_vars['product_brand']) && is_numeric($q_vars['product_brand']) && $q_vars['product_brand'] != 0) {
            $term = get_term_by('id', $q_vars['product_brand'], 'product_brand');
            $q_vars['product_brand'] = $term->slug;
        }
        if ($pagenow == 'edit.php' && isset($q_vars['post_type']) && $q_vars['post_type'] == $post_type && isset($q_vars['product_type']) && is_numeric($q_vars['product_type']) && $q_vars['product_type'] != 0) {
            $term = get_term_by('id', (int) $q_vars['product_type'], 'product_type');
            $q_vars['product_type'] = $term->slug;
        }
    }

    public function logout_customer() {
        session_start();
        if (isset($_SESSION['customer_id']))
            unset($_SESSION['customer_id']);
        if (isset($_SESSION['cart']))
            unset($_SESSION['cart']);
        if (isset($_SESSION['customer']))
            unset($_SESSION['customer']);
    }

    public function clear_customer_fields() {

        if (isset($_SESSION['customer']))
            unset($_SESSION['customer']);
    }

    public function add_new_products_columns($cols) {

        $res = array();
        $res['cb'] = $cols['cb'];
        unset($cols[cb]);
        $res['image'] = '';
        $res['title'] = $cols['title'];
        unset($cols['title']);
        $res['comments'] = $cols['comments'];
        unset($cols['comments']);
        $res['product_number'] = __('Product Number', 'com_shop');
        $res['menu_order'] = __('Ordering', 'com_shop');
        $res['price'] = __('Price', 'com_shop');
        $res['category'] = __('Category', 'com_shop');
        $res['manufacturer'] = __('Manufacturer', 'com_shop');
        $res['published'] = __('Published', 'com_shop');

        unset($cols['date']);
        $cols = (array) array_merge((array) $res, (array) $cols);

        return $cols;
    }

    public function add_new_orders_columns($cols) {

        $res = array();
        $res['cb'] = $cols['cb'];
        unset($cols[cb]);

        $res['id'] = __('Order Id', 'com_shop');
        $res['billing'] = __('Billing', 'com_shop');
        $res['shipping'] = __('Shipping', 'com_shop');
        unset($cols['title']);
        unset($cols['comments']);
        $res['order_status'] = __('Order Status', 'com_shop');
        $res['total_amount'] = __('Total Amount', 'com_shop');
        $res['date'] = $cols['date'];


        unset($cols['date']);
        //  $cols = (array) array_merge((array) $res, (array) $cols);

        return $res;
    }

    public function manage_product_columns($column_name, $id) {

        echo request::execute("component=shop&con=products&task=fill_column&column={$column_name}&id={$id}");
    }

    public function manage_order_columns($column_name, $id) {



        echo request::execute("component=shop&con=orders&task=fill_column&column={$column_name}&id={$id}");
    }

    public function delete_transaction($id) {

        $row = get_post($id);

        if ($row->post_type == 'product') {

            request::execute('component=shop&con=products&task=delete&ids=' . $id);
        }

        if ($row->post_type == 'shop_order') {

            request::execute('component=shop&con=orders&task=delete&id=' . $id);
        }
    }

    public function init_term_meta_table() {
        global $wpdb;
        $wpdb->termmeta = $wpdb->prefix . 'shop_termmeta';
    }

    public function remove_read_more() {
        remove_filter('get_the_excerpt', 'twentyeleven_custom_excerpt_more');
    }

    public function orders_by_customer_query($vars) {
        global $typenow, $wp_query;
        if ($typenow == 'shop_order' && isset($_GET['_customer_id']) && $_GET['_customer_id'] > 0) :

            $vars['meta_key'] = '_customer_id';
            $vars['meta_value'] = (int) $_GET['_customer_id'];

        endif;

        return $vars;
    }

    public function order_search_custom_fields($wp) {
        global $pagenow, $wpdb;

        if ('edit.php' != $pagenow)
            return $wp;
        if (!isset($wp->query_vars['s']) || !$wp->query_vars['s'])
            return $wp;
        if ($wp->query_vars['post_type'] != 'shop_order')
            return $wp;



        $helper = Factory::getApplication('shop')->getHelper('customer');

        $billing = $helper->get_billing_fields();
        $shipping = $helper->get_shipping_fields();


        $search_fields = array();
        while ($f = $billing->get_field()) {
            $search_fields[] = "_" . $f->get_name();
        }

        while ($f = $shipping->get_field()) {
            $search_fields[] = "_" . $f->get_name();
        }





        $post_ids = $wpdb->get_col($wpdb->prepare('SELECT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key IN (' . '"' . implode('","', $search_fields) . '"' . ') AND meta_value LIKE "%%%s%%"', esc_attr($_GET['s'])));


        $post_ids = array_merge($post_ids, $wpdb->get_col($wpdb->prepare('
		SELECT ' . $wpdb->posts . '.ID 
		FROM ' . $wpdb->posts . ' 
		LEFT JOIN ' . $wpdb->postmeta . ' ON ' . $wpdb->posts . '.ID = ' . $wpdb->postmeta . '.post_id
		LEFT JOIN ' . $wpdb->users . ' ON ' . $wpdb->postmeta . '.meta_value = ' . $wpdb->users . '.ID
		WHERE 
			post_excerpt 	LIKE "%%%1$s%%" OR
			post_title 		LIKE "%%%1$s%%" OR
			(
				meta_key		= "_customer_id" AND
				(
					user_login		LIKE "%%%1$s%%" OR
					user_nicename	LIKE "%%%1$s%%" OR
					user_email		LIKE "%%%1$s%%" OR
					display_name	LIKE "%%%1$s%%"
				)
			)
		', esc_attr($_GET['s'])
        )));

        // Add ID
        $search_order_id = str_replace('Order #', '', $_GET['s']);
        if (is_numeric($search_order_id))
            $post_ids[] = $search_order_id;

        // Add blank ID so not all results are returned if the search finds nothing
        $post_ids[] = 0;

        // Remove s - we don't want to search order name
        unset($wp->query_vars['s']);

        // so we know we're doing this
        $wp->query_vars['shop_order_search'] = true;

        // Search by found posts
        $wp->query_vars['post__in'] = $post_ids;
    }

    public function order_search_label($query) {
        global $pagenow, $typenow;

        if ('edit.php' != $pagenow)
            return $query;
        if ($typenow != 'shop_order')
            return $query;
        if (!get_query_var('shop_order_search'))
            return $query;

        return $_GET['s'];
    }

    public function add_custom_query_var_admin($public_query_vars) {
        $public_query_vars[] = 'sku';
        $public_query_vars[] = 'shop_order_search';

        return $public_query_vars;
    }

    public function manage_user_columns($columns) {
        if (!current_user_can('manage_options'))
            return $columns;

        $columns['billing_address'] = __('Billing Address', 'com_shop');
        $columns['shipping_address'] = __('Shipping Address', 'com_shop');

        $columns['orders_count'] = __('Orders', 'com_shop');
        return $columns;
    }

    public function manage_user_column_values($value, $column_name, $user_id) {

        return Request::execute("component=shop&con=user&col=" . $column_name . "&id=" . $user_id);
    }

    public function customer_meta_fields($user) {
        echo Request::execute("component=shop&con=user&task=user_form&id=" . $user->ID);
    }

    public function customer_meta_save($id) {
        Request::execute("component=shop&con=user&task=save&id=" . $id);
    }

    public function customer_clear_session() {
        session_start();
        unset($_SESSION['customer']);
    }

}

add_action('admin_init', 'my_add_meta_box');

function my_add_meta_box() {
    add_meta_box('custom-meta-box', __('Shop related pages', 'com_shop'), 'my_nav_menu_item_link_meta_box', 'nav-menus', 'side', 'default');
}

function my_nav_menu_item_link_meta_box() {

    global $_nav_menu_placeholder, $nav_menu_selected_id;
    $_nav_menu_placeholder = 0 > $_nav_menu_placeholder ? $_nav_menu_placeholder - 1 : -1;

    $current_tab = 'create';
    if (isset($_REQUEST['customlink-tab']) && in_array($_REQUEST['customlink-tab'], array('create', 'all'))) {
        $current_tab = $_REQUEST['customlink-tab'];
    }

    $removed_args = array(
        'action',
        'customlink-tab',
        'edit-menu-item',
        'menu-item',
        'page-tab',
        '_wpnonce',
    );
    ?>
    <div class="shop-controllersdiv" id="customlinkdiv">

        <input type="hidden" value="custom" name="menu-item[<?php echo $_nav_menu_placeholder; ?>][menu-item-type]" />
        <p id="menu-item-url-wrap">
            <label class="howto" for="shop-controllers">
                <span><?php _e('URL', 'com_shop'); ?></span>

                <select id="shop-controllers" name="menu-item[<?php echo $_nav_menu_placeholder; ?>][menu-item-url]">
                    <option value="<?php echo Route::get("component=shop&con=account"); ?>"><?php _e('custumer account', 'com_shop'); ?></option>
                    <option value="<?php echo Route::get("component=shop&con=cart"); ?>"><?php _e('custumer cart', 'com_shop'); ?></option>
                    <option value="<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>"><?php _e('checkout', 'com_shop'); ?></option>
                </select>

            </label>
        </p>

        <p id="menu-item-name-wrap">
            <label class="howto" for="shop-controllers-name">
                <span><?php _e('Label', 'com_shop'); ?></span>
                <input id="shop-controllers-name" name="menu-item[<?php echo $_nav_menu_placeholder; ?>][menu-item-title]" type="text" class="regular-text menu-item-textbox input-with-default-title" title="<?php esc_attr_e('Menu Item'); ?>" />
            </label>
        </p>

        <p class="button-controls">
            <span class="add-to-menu">
                <img class="waiting" src="<?php echo esc_url(admin_url('images/wpspin_light.gif')); ?>" alt="" />
                <input type="submit"<?php disabled($nav_menu_selected_id, 0); ?> class="button-secondary" onclick="return addShopPage()" value="<?php esc_attr_e('Add to Menu'); ?>" name="shop-controllers" id="shop-controllersdiv" />
            </span>
        </p>


        <script type="text/javascript">
                    function addShopPage() {
                        jQuery('.shop-controllersdiv img.waiting').show();
                        wpNavMenu.addLinkToMenu(jQuery("#shop-controllers").val(), jQuery("#shop-controllers-name").val(), wpNavMenu.addMenuItemToBottom, function() {
                            // Remove the ajax spinner
                            jQuery('.shop-controllersdiv img.waiting').hide();
                            // Set custom link form back to defaults
                            jQuery('#shop-controllers-name').val('').blur();

                        });
                        return false;
                    }

        </script>



    </div>
    <?php
}

add_action("delete_term", 'com_shop_delete_term', 5, 3);

function com_shop_delete_term($term_id, $tt_id, $taxonomy) {
    $db = Factory::getDBO();
    $term_id = (int) $term_id;

    if (!$term_id)
        return;


    $db->setQuery("DELETE FROM #_shop_termmeta WHERE `term_id` = " . $term_id);
}

add_action('quick_edit_custom_box', 'com_shop_admin_product_quick_edit', 10, 2);

/**
 * Custom quick edit - form
 *
 * @access public
 * @param mixed $column_name
 * @param mixed $post_type
 * @return void
 */
function com_shop_admin_product_quick_edit($column_name, $post_type) {

    if ($column_name != 'price' || $post_type != 'product')
        return;
    ?>
    <fieldset class="inline-edit-col-left">
        <div id="com-shop-fields" class="inline-edit-col">
            <h4><?php _e('Product Data', 'com_shop'); ?></h4>
            <label>
                <span class="title"><?php _e('Ordering', 'com_shop'); ?></span>
                <span class="input-text-wrap">
                    <input type="text" name="menu_order" class="text sku" value="">
                    <input type="hidden" name="in_quick_edit" value="1" />
                </span>
            </label>
            <br class="clear" />

            <label>
                <span class="title"><?php _e('Price', 'com_shop'); ?></span>
                <span class="input-text-wrap">
                    <input type="text" name="price" class="text sku" value="">

                </span>
            </label>
            <br class="clear" />

        </div>
    </fieldset>
    <?php
}