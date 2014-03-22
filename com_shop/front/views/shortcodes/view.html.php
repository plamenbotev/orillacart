<?php

class shopViewShortcodes extends view {

    public function shortcodes_head_data() {

        $used = $this->shortcodes;
        $mainframe = Factory::getMainframe();
        foreach ((array) $used as $code) {
            switch ($code) {

                case "recent_products":
                case "special_products":
                case "on_sale_products":
                    $mainframe->addscript('jquery');
                    $mainframe->addscript('shop_helper', Factory::getApplication('shop')->getComponentUrl() . '/front/js/shop_helper.js');
                    $mainframe->addstyle('bootstrap', Factory::getApplication('shop')->getAssetsUrl() . '/bootstrap.css');
					$mainframe->addstyle('icons', Factory::getApplication('shop')->getAssetsUrl() . '/icons.css');
					$mainframe->addstyle('bootstrap-buttons',Factory::getApplication('shop')->getAssetsUrl() . '/buttons.css');
					$mainframe->addstyle('frontend-styles', Factory::getApplication('shop')->getAssetsUrl() . '/frontend-styles.css');

                    $mainframe->addCustomHeadTag('ajaxurl', "
                <script type='text/javascript'>
                    jQuery(function() { 
                    window.shop_helper.ajaxurl = '" . admin_url('admin-ajax.php') . "';
                   
                    });
                </script>
				
            ");

                    Factory::getMainframe()->addscript("jquery-equalheights", Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.equalheights.js");

                    Factory::getMainframe()->addCustomHeadTag('grid-equals-li', "<script type='text/javascript'>jQuery(function() { jQuery('ul#activeFilter_itemList').equalHeights(); jQuery('ul#activeFilter_itemList').equalWidths();  }); </script>");

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