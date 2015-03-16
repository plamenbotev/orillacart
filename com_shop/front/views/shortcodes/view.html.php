<?php

class shopViewShortcodes extends view {

    public function shortcodes_head_data() {

        $used = $this->shortcodes;
        $Head = Factory::getHead();
        foreach ((array) $used as $code) {
            switch ($code) {

                case "recent_products":
                case "special_products":
                case "on_sale_products":
                    $Head->addscript('jquery');
                    $Head->addscript('shop_helper', Factory::getComponent('shop')->getComponentUrl() . '/front/js/shop_helper.js');
                    $Head->addstyle('bootstrap', Factory::getComponent('shop')->getAssetsUrl() . '/bootstrap.css');
                    $Head->addstyle('icons', Factory::getComponent('shop')->getAssetsUrl() . '/icons.css');
                    $Head->addstyle('bootstrap-buttons', Factory::getComponent('shop')->getAssetsUrl() . '/buttons.css');
                    $Head->addstyle('frontend-styles', Factory::getComponent('shop')->getAssetsUrl() . '/frontend-styles.css');

                    $Head->addCustomHeadTag('ajaxurl', "
                <script type='text/javascript'>
                    jQuery(function() { 
                    window.shop_helper.ajaxurl = '" . admin_url('admin-ajax.php') . "';
                   
                    });
                </script>
				
            ");

                    Factory::getHead()->addscript("jquery-equalheights", Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.equalheights.js");

                    Factory::getHead()->addCustomHeadTag('grid-equals-li', "<script type='text/javascript'>jQuery(function() { jQuery('ul#activeFilter_itemList').equalHeights(); jQuery('ul#activeFilter_itemList').equalWidths();  }); </script>");

                    break;
            }
        }
    }

    public function recent_products() {

        $params = $this->params;
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'ignore_sticky_posts' => 1,
            'posts_per_page' => $params->get('per_page', 12),
            'orderby' => $params->get('orderby', 'desc'),
            'order' => $params->get('order', 'date')
        );

        ob_start();

        $this->assign('products', new WP_Query($args));

        $model = Model::getInstance('product', 'shop');
        $this->setModel($model);

        $this->loadTemplate('products_grid');

        wp_reset_query();
    }

    public function special_products() {

        $params = $this->params;
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'ignore_sticky_posts' => 1,
            'posts_per_page' => $params->get('per_page', 12),
            'orderby' => $params->get('orderby', 'desc'),
            'order' => $params->get('order', 'date'),
            'meta_query' => array(
                array(
                    'key' => '_special',
                    'value' => 'yes',
                )
            )
        );

        ob_start();

        $this->assign('products', new WP_Query($args));

        $model = Model::getInstance('product', 'shop');
        $this->setModel($model);

        $this->loadTemplate('products_grid');

        wp_reset_query();
    }

    public function on_sale_products() {

        $params = $this->params;
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'ignore_sticky_posts' => 1,
            'posts_per_page' => $params->get('per_page', 12),
            'orderby' => $params->get('orderby', 'desc'),
            'order' => $params->get('order', 'date'),
            'meta_query' => array(
                array(
                    'key' => '_onsale',
                    'value' => 'yes',
                )
            )
        );

        ob_start();

        $this->assign('products', new WP_Query($args));

        $model = Model::getInstance('product', 'shop');
        $this->setModel($model);

        $this->loadTemplate('products_grid');

        wp_reset_query();
    }

}
