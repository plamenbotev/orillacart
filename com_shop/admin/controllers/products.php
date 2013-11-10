<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerProducts extends controller {

    protected function __default() {
        if (!request::is_internal()) {
            die();
        }
        $this->getView('listproducts');
        parent::display();
    }

    protected function fill_column() {


        if (!request::is_internal()) {
            die();
        }
        $col = request::getWord('column');
        $id = request::getInt('id');





        $this->getView('listproducts');

        $this->view->assign('col', $col);
        $this->view->assign('id', $id);
        $model = $this->getModel('product_admin');

        $p = $model->load($id);

        $this->view->assign('p', $p);


        $this->view->setModel($model);


        parent::display('fill_column');
    }

    protected function delete() {

        $model = $this->getModel('product_admin');


        $ids = (array) array_map('intval', (array) request::getVar('ids', array()));


        $model->deleteProducts($ids);
    }

    protected function changestate() {


        $model = $this->getModel('product_admin');

        $pid = (int) $_POST['id'];

        ob_end_clean();

        $res = new stdClass();

        $res->status = $model->changeState($pid);

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($res);
        exit();
    }

    protected function save() {

        if (!request::is_internal())
            throw new Exception(__("access denied", "com_shop"));
        $model = $this->getModel('product_admin');
        $model->save($_POST);
    }

    protected function save_variation() {

        if (!request::is_internal())
            throw new Exception(__("access denied", "com_shop"));
        $model = $this->getModel('product_admin');
        $model->save($_POST);
    }

    protected function meta_boxes() {
        if (!request::is_internal())
            throw new Exception(__("access denied", "com_shop"));

        $this->getView('product');
        global $post;

        $model = $this->getModel('product_admin');
        $shipping_model = $this->getModel('shipping');
        $pid = (int) $post->ID;
        $stock_model = $this->getModel('stockroom');
        $att_model = $this->getModel('attributes');
        $cat_model = $this->getModel('category');
        $templates = $cat_model->getProductTemplates();

        $this->view->assign('templates', $templates);
        $this->view->assign('post', $post);
        $this->view->assign('stock_rooms', $stock_model->getAllStockRooms());
        $tax_model = $this->getModel('tax');

        $this->view->assign('tax_groups', (array) $tax_model->getAllGroups());
        $this->view->assign('attributes', $att_model->getattributes($pid, 'product'));
        if ($post->post_parent) {
            $this->view->assign('all_attributes', (array) $model->getProductAttributes($post->post_parent));
        } else {
            $this->view->assign('all_attributes', (array) $model->getProductAttributes($pid));
            $this->view->assign('attribute_sets', $att_model->getAllAttributeSets());
            $this->view->assign('product_attribute_sets', $model->getAttributeSets($pid));
        }

        $row = new stdClass();
        $row->product = Factory::getApplication('shop')->getTable('product')->load($pid);

        if ($post->post_parent) {

            $this->view->assign('variations_assoc', $model->get_variation_properties($pid));

            $parent = Factory::getApplication('shop')->getTable('product')->load($post->post_parent);

            $this->view->assign('parent_id', $parent->post->ID);
            $this->view->assign('parent_title', $parent->post->post_title);
            unset($parent);
        } else {
            $this->view->assign('parent_id', '');
            $this->view->assign('parent_title', '');
        }

        $row->cats = array();
        $row->cats = (array) $model->getProductCatIds($pid);
        $row->brands = (array) $model->get_product_brands_ids($pid);

        $row->shipping_groups = (array) $model->get_product_shipping_groups_ids($pid);
        $row->stockrooms = array();
        $row->stockrooms = $model->getProductStockRooms($pid);

        $brands_model = $this->getModel("brands");

        $stockroom = $this->getModel('stockroom');
        $this->view->assign('stockrooms', $stockroom->getAllStockRooms());
        $this->view->assign('row', $row);
        $this->view->assign('brands', $brands_model->getAllBrands());
        $this->view->assign('shipping_groups', $shipping_model->getAllShippingGroups());
        $this->view->assignref('settings', Factory::getApplication('shop')->getParams());

        $path = wp_upload_dir();
        $this->view->assign('images_path', $path['baseurl']);

        remove_meta_box('slugdiv', 'product', 'normal');
        remove_meta_box('tagsdiv-product_brand', 'product', 'side');

        $this->view->poduct_meta_js();

        add_meta_box('product_data', 'Product information', array($this->view, 'product_meta'), 'product', 'normal', 'high');
        add_meta_box('product_category', 'Category', array($this->view, 'category_meta'), 'product', 'side');
    }

    public function create_variation() {

        $model = $this->getModel('product_admin');

        $props = array_map('intval', (array) $_POST['property']);
        $product = request::getInt('parent', null);

        $res = new stdClass();

        try {
            $model->create_variation($product, $props);
            $res->msg = __("Variation was created.", "com_shop");
        } catch (Exception $e) {
            $res->msg = $e->getMessage();
        }



        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($res);
        die();
    }

}