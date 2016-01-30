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

        $input = Factory::getApplication()->getInput();

        $col = $input->get('column', null, "WORD");
        $id = $input->get('id', 0, "INT");





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

        $input = Factory::getApplication()->getInput();

        $ids = (array) array_map('intval', (array) $input->get('ids', array(), "ARRAY"));


        $model->deleteProducts($ids,true);
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

        $input = Factory::getApplication()->getInput();

        $model->save($input->post);
    }

    protected function save_variation() {

        if (!request::is_internal())
            throw new Exception(__("access denied", "com_shop"));
        $model = $this->getModel('product_admin');

        $input = Factory::getApplication()->getInput();

        $model->save($input->post);
    }

    protected function meta_boxes() {
        if (!request::is_internal())
            throw new Exception(__("access denied", "com_shop"));

        $this->getView('product');
        global $post;


        $app = Factory::getComponent("shop");


        Model::addIncludePath($app->getName(), $app->getComponentRootPath() . "/front/models");

        $model = $this->getModel('product_admin');
        $front_model = $this->getModel('product');

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
        $row->product = Factory::getComponent('shop')->getTable('product')->load($pid);

        if ($front_model->is_variable($pid)) {
            $variations = $front_model->get_child_products($pid);

            $this->view->assign("variations", $variations);
        }

        if ($post->post_parent) {

            $this->view->assign('variations_assoc', $model->get_variation_properties($pid));

            $parent = Factory::getComponent('shop')->getTable('product')->load($post->post_parent);

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
        $this->view->assignref('settings', Factory::getComponent('shop')->getParams());

        $path = wp_upload_dir();
        $this->view->assign('images_path', $path['baseurl']);

        remove_meta_box('slugdiv', 'product', 'normal');
        remove_meta_box('tagsdiv-product_brand', 'product', 'side');
        remove_meta_box('tagsdiv-shipping_group', 'product', 'side');

        $this->view->poduct_meta_js();

        add_meta_box('product_data', 'Product information', array($this->view, 'product_meta'), 'product', 'normal', 'high');
        add_meta_box('product_category', 'Category', array($this->view, 'category_meta'), 'product', 'side');
    }

    public function create_variation() {

        $app = Factory::getComponent("shop");
        Model::addIncludePath($app->getName(), $app->getComponentRootPath() . "/front/models");

        $model = $this->getModel('product_admin');
        $front_model = $this->getModel('product');

        $input = Factory::getApplication()->getInput();

        $props = array_map('intval', (array) $input->get('property', array(), "ARRAY"));
        $product = $input->get('parent', null, "INT");
        $this->getView('product');
        $res = new stdClass();

        try {
            $model->create_variation($product, $props);
            $res->msg = __("Variation was created.", "com_shop");

            if ($front_model->is_variable($product)) {
                $variations = $front_model->get_child_products($product);

                $this->view->assign("variations", $variations);

                ob_start();

                $this->view->loadTemplate("variations_manager");

                $res->variations = ob_get_contents();
                ob_end_clean();
            }
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

    public function delete_variation() {

        $app = Factory::getComponent("shop");
        Model::addIncludePath($app->getName(), $app->getComponentRootPath() . "/front/models");

        $model = $this->getModel('product_admin');
        $front_model = $this->getModel('product');

        $input = Factory::getApplication()->getInput();


        $product = $input->get('id', null, "INT");

        $post = get_post($product);
        $ok = false;

        if ($post->post_parent) {
            if (wp_delete_post($product, true) !== false) {
                $ok = true;
            }
        }

        $this->getView('product');
        $res = new stdClass();

        try {
            if ($ok) {
                $res->msg = __("Variation was deleted.", "com_shop");

                if ($front_model->is_variable($post->post_parent)) {
                    $variations = $front_model->get_child_products($post->post_parent);

                    $this->view->assign("variations", $variations);

                    ob_start();

                    $this->view->loadTemplate("variations_manager");

                    $res->variations = ob_get_contents();
                    ob_end_clean();
                } else {
                    $res->variations = "";
                }
            } else {
                $res->msg = __("Error deleteing the selected variation.", "com_shop");
            }
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
