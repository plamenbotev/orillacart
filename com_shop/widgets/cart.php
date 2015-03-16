<?php

class Widget_Shop_Cart extends WP_Widget {

    protected $view = null;

    /**
     * Constructor
     *
     * Setup the widget with the available options
     */
    public function __construct() {

        $options = array(
            'description' => __("Shopping cart", 'com_shop'),
        );

        // Create the widget
        parent::__construct('com_shop_cart', __('Shopping Cart', 'com_shop'), $options);

        if (is_active_widget(false, false, $this->id_base) && !is_admin()) {

            Factory::getHead()->addscript('jquery');
            Factory::getHead()->addScript('tipsy', Factory::getComponent('shop')->getAssetsUrl() . "/js/tipsy.js");
            Factory::getHead()->addScript('jquery-validate', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.validate.js");
            Factory::getHead()->addScript('add-to-cart-widget', Factory::getComponent('shop')->getAssetsUrl() . "/js/add-to-cart-widget.js");
            Factory::getHead()->addStyle('tipsy', Factory::getComponent('shop')->getAssetsUrl() . "/tipsy.css");
            Factory::getHead()->addStyle('bootstrap', Factory::getComponent('shop')->getAssetsUrl() . "/bootstrap.css");
            Factory::getHead()->addstyle('icons', Factory::getComponent('shop')->getAssetsUrl() . '/icons.css');
            Factory::getHead()->addstyle('bootstrap-buttons', Factory::getComponent('shop')->getAssetsUrl() . '/buttons.css');

            Factory::getHead()->addscript('block', Factory::getComponent('shop')->getAssetsUrl() . "/js/block.js");
        }
        add_filter('orillacart_add_to_cart_json', array($this, 'update_cart_content'));
    }

    /**
     * Widget
     *
     * Display the widget in the sidebar
     *
     * @param	array	sidebar arguments
     * @param	array	instance
     */
    public function update_cart_content($res) {

        $cart = Helper::getInstance('cart', 'shop');

        $view = view::getInstance('widgets', 'shop');
        $view->assign('widget', $this);
        ob_start();
        $view->cart_ajax_data();
        $data = ob_get_contents();
        ob_end_clean();
        $res['cart_ajax_data'] = $data;

        return $res;
    }

    public function widget($args, $instance) {

        extract($args);

        $title = apply_filters(
                'widget_title', ( $instance['title'] ) ? $instance['title'] : __('Cart', 'com_shop'), $instance, $this->id_base
        );


        echo $before_widget;
        echo $before_title . $title . $after_title;

        //instantiate cart widget view assign the widget data and display it
        $view = view::getInstance('widgets', 'shop');
        $view->assign('widget', $this);
        $view->cart_widget();

        echo $after_widget;
    }

    /**
     * Update
     *
     * Handles the processing of information entered in the wordpress admin
     *
     * @param	array	new instance
     * @param	array	old instance
     * @return	array	instance
     */
    public function update($new_instance, $old_instance) {
        $instance = $old_instance;

        // Save new values
        $instance['title'] = strip_tags($new_instance['title']);
        $instance['taxonomy'] = isset($new_instance['taxonomy']) ? $new_instance['taxonomy'] : '';

        return $instance;
    }

    /**
     * Form
     *
     * Displays the form for the wordpress admin
     *
     * @param	array	instance
     */
    public function form($instance) {
        $title = (isset($instance['title'])) ? esc_attr($instance['title']) : null;

        // Widget title
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('Title:', 'com_shop'); ?></label>
            <input type="text" class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" value="<?php echo esc_attr($title); ?>" />
        </p>
        <?php
    }

}

function orillacart_register_widgets() {
    register_widget('Widget_Shop_Cart');
}

add_action('widgets_init', 'orillacart_register_widgets');
