<?php

class shopControllerProduct_list extends controller {

    protected function __default() {

        $model = $this->getModel('product_list');

        $this->getView('product_list');

        $input = Factory::getApplication()->getInput();

        $cid = $input->get('cid', null, "INT");
        $category = null;
        $params = Factory::getComponent('shop')->getParams();
        $exclude = array();
        if ($params->get('exclude_frontpage_cat') && $params->get('front_page_cat')) {
            $exclude[] = $params->get('front_page_cat');

            if ($cid == $params->get('front_page_cat')) {
                wp_safe_redirect(Route::get("component=shop"));
                exit;
            }
            $cid = $params->get('front_page_cat');
        }

        $category = $model->load($cid);
        $products = $model->getProducts();

        $this->view->setModel($this->getModel('product'));
        $this->view->assign('category', $category);

        if ($params->get('display_cetegories')) {
            //if we have category as homepage, set the $cid to null, to load all root subcategories
            if ($cid == $params->get('front_page_cat') && !$input->get('cid', null, "INT")) {

                $cid = null;
            }
            $this->view->assign('sub_cats', $model->getSubCats($cid, $exclude));
        }

        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');
        $products_per_row = $term_meta->get($cid, 'products_per_row', true);

        if (empty($products_per_row)) {
            $products_per_row = Factory::getComponent('shop')->getParams()->get('products_per_row');
        }

        $this->view->assign('products_per_row', $products_per_row);

        if (isset($_SESSION['product_list_order'])) {
            $ordering = $_SESSION['product_list_order'];
        } else {
            $ordering = Factory::getComponent('shop')->getParams()->get('productSort', 'id');
        }

        $this->view->assign("ordering", $ordering);

        parent::display();
    }

    protected function brand() {

        $model = $this->getModel('product_list');
        $this->getView('product_list');

        $input = Factory::getApplication()->getInput();

        $cid = $input->get('id', null, "INT");
        $category = null;
        $products = $model->getProducts();

        $this->view->setModel($this->getModel('product'));

        if (isset($_SESSION['product_list_order'])) {
            $ordering = $_SESSION['product_list_order'];
        } else {
            $ordering = Factory::getComponent('shop')->getParams()->get('productSort', 'id');
        }

        $this->view->assign("ordering", $ordering);

        parent::display();
    }

}
