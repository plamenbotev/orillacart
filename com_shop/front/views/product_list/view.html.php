<?php

class shopViewProduct_list extends view {

    public function display() {

        global $wp_query;


        $big = 999999999;

        $pagination = paginate_links(array(
            'base' => str_replace($big, '%#%', get_pagenum_link($big)),
            'format' => '?paged=%#%',
            'current' => max(1, get_query_var('paged')),
            'total' => $wp_query->max_num_pages
        ));
        $this->assign('pagination', $pagination);
        add_filter('override_shop', array($this, 'override_templates'), 1, 9);

        Factory::getMainframe()->addscript('jquery');

        $app = Factory::getApplication('shop');

        Factory::getMainframe()->addscript("jquery-equalheights", $app->getAssetsUrl() . "/js/jquery.equalheights.js");

        Factory::getMainframe()->addCustomHeadTag('grid-equals-li', "<script type='text/javascript'>jQuery(function() { jQuery('ul#activeFilter_itemList').equalHeights(); jQuery('ul#activeFilter_itemList').equalWidths();  }); </script>");


        $tpl = request::getString('list_type', null);

        if (!$tpl && isset($_SESSION['list_type'])) {
            $tpl = $_SESSION['list_type'];
        }

        if (!in_array(strtolower($tpl), array('list', 'grid')))
            $tpl = (string) empty($this->category->view_style) ? '' : $this->category->view_style;
        if (!in_array(strtolower($tpl), array('list', 'grid')))
            $tpl = $app->getParams()->get('list_type');

        $this->assign('list_type', $tpl);
        if (request::getString('list_type', null)) {
            $_SESSION['list_type'] = $tpl;
        }
        $this->assign('products_per_row', $app->getParams()->get('products_per_row'));
        $this->assign('products_count', $wp_query->post_count);

        //set proper page title
        $obj = get_queried_object();

        if (isset($obj->taxonomy) && !empty( $obj->taxonomy ) && $obj->taxonomy == 'product_cat') {
            $path = get_ancestors($obj->term_id, $obj->taxonomy);

            $path[] = $obj->term_id;
            $path = array_map('intval', $path);


            $terms = get_terms("product_cat", array('hide_empty' => 0,
                'hierarchial' => 1,
                'include' => $path)
            );

            $title = array();
            foreach ((array) $terms as $o) {
                $title[] = $o->name;
            }
            Factory::getMainframe()->setPageTitle(implode(' - ', $title));
        } else if (isset($obj->taxonomy) && !empty( $obj->taxonomy ) && in_array($obj->taxonomy, array('product_tags', 'product_brand', 'product_type'))) {
            Factory::getMainframe()->setPageTitle($obj->name);
        } else {
            $page = get_post(Factory::getApplication("shop")->getPArams()->get("page_id"));

            Factory::getMainframe()->setPageTitle(esc_html(stripslashes($page->post_title)));
        }

        parent::display($tpl);
    }

    public function override_templates($paths) {
		$paths[] = dirname(__FILE__) . "/templates/" . empty($this->category->list_template) ? 'list.tpl.php': $this->category->list_template;
	
		return $paths;
    }

}