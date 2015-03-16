<?php

class shopViewProduct_list extends view {

    public function display() {

        global $wp_query;

        $input = Factory::getApplication()->getInput();

        $big = 999999999;

        $pagination = paginate_links(array(
            'base' => str_replace($big, '%#%', get_pagenum_link($big)),
            'format' => '?paged=%#%',
            'current' => max(1, get_query_var('paged')),
            'total' => $wp_query->max_num_pages,
            'type' => "array"
        ));





        $this->assign('pagination', $pagination);
        add_filter('edit_template_paths_shop', array($this, 'override_templates'), 1, 9);

        Factory::getHead()->addscript('jquery');

        $app = Factory::getComponent('shop');

        Factory::getHead()->addscript("jquery-equalheights", $app->getAssetsUrl() . "/js/jquery.syncheight.js");

        Factory::getHead()->addCustomHeadTag('grid-equals-li', "<script type='text/javascript'> 
            jQuery(window).load(function(){  
                    jQuery('#com-shop .productsGrid').each(function(i){
                        jQuery('.gridItem',this).syncHeight({ 'updateOnResize': true});  
                    }); 
            }); </script>");


        $tpl = $input->get('list_type', null, "STRING");

        if (!$tpl && isset($_SESSION['list_type'])) {
            $tpl = $_SESSION['list_type'];
        }

        if (!in_array(strtolower($tpl), array('list', 'grid')))
            $tpl = (string) empty($this->category->view_style) ? '' : $this->category->view_style;
        if (!in_array(strtolower($tpl), array('list', 'grid')))
            $tpl = $app->getParams()->get('list_type');

        $this->assign('list_type', $tpl);
        if ($input->get('list_type', null, "STRING")) {
            $_SESSION['list_type'] = $tpl;
        }
        $this->assign('products_per_row', $app->getParams()->get('products_per_row'));
        $this->assign('products_count', $wp_query->post_count);

        //set proper page title
        $obj = get_queried_object();

        if (isset($obj->taxonomy) && !empty($obj->taxonomy) && $obj->taxonomy == 'product_cat') {
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
            Factory::getHead()->setPageTitle(implode(' - ', $title));
        } else if (isset($obj->taxonomy) && !empty($obj->taxonomy) && in_array($obj->taxonomy, array('product_tags', 'product_brand', 'product_type'))) {
            Factory::getHead()->setPageTitle($obj->name);
        } else {
            $page = get_post(Factory::getComponent("shop")->getPArams()->get("page_id"));

            Factory::getHead()->setPageTitle(esc_html($page->post_title));
        }

        $this->loadTemplate($tpl);
    }

    public function override_templates(array $paths) {
        if (isset($this->category->list_template)) {
            foreach ($paths as $k => $v) {
                $paths[$k] = trailingslashit($v) . $this->category->list_template;
            }
        }
        return $paths;
    }

}
