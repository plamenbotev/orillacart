<?php

class shopControllerOrders extends controller {
    /* Default orders list */

    protected function __default() {

        if (!request::is_internal()) {
            return false;
        }

        $this->getView('orders');
        parent::display();
    }

    protected function meta_boxes() {
        global $post;
        remove_meta_box('submitdiv', 'shop_order', 'side');
        remove_meta_box('slugdiv', 'shop_order', 'normal');


        $this->getView('orders');

        add_meta_box('orillacart-order-actions', __('Order Actions', 'com_shop'), array($this->view, 'order_actions_box'), 'shop_order', 'side', 'high');


        $id = $post->ID;

        $model = $this->getModel('orders');
        $order_helper = helper::getInstance("order", "shop");
        
        $product = $this->getModel('product_admin');

        $order = $model->load_order($id);

        $this->view->assign('user', get_userdata($order['order']->customer_id));

        $statuses = get_terms('order_status', array('hide_empty' => false));

        $country = $this->getModel('country');

        $this->view->assign('statuses', $statuses);

        $this->view->setModel($product);
        
        $this->view->setModel($model);

        $price = Factory::getApplication('shop')->getHelper('price');
       
        $this->view->assign("taxes",$order_helper->get_order_taxes($id));
        $this->view->assign('price', $price);
        $this->view->assign('order', $order['order']);

        foreach ((array) $order['items'] as $k => $item) {
            $order['items'][$k] = $model->prepare_order_item($item);
        }


        $this->view->assign('items', (array) $order['items']);

        $this->view->assign('billing', Factory::getApplication('shop')->getHelper('order')->get_order_billing($id));
        $this->view->assign('shipping', Factory::getApplication('shop')->getHelper('order')->get_order_shipping($id));



        $all_countries = $country->getCountryList(true);

        $this->view->assign('countries', $all_countries->loadObjectList());
        unset($all_countries);
        $states = array();


        if (!empty($order['order']->customer_billing['country_code'])) {


            $states = $country->getStatesByCountry($order['order']->customer_billing['country_code']);

            $this->view->assign('states_billing', $states);
            unset($states);
        }

        if (!empty($order['order']->customer_shipping['country_code'])) {


            $states = $country->getStatesByCountry($order['order']->customer_shipping['country_code']);

            $this->view->assign('states_shipping', $states);
            unset($states);
        }

        $this->view->assign('shipping_methods', $model->get_shipping_rates($id));

        //add styles and js to order edit page
        //must be done here, because when the view methods are executed, the proper event is already fiered

        $this->view->order_meta_js();


        add_meta_box('orillacart-order-data', __('Order Data', 'com_shop'), array($this->view, 'order_data'), 'shop_order', 'normal', 'high');
        add_meta_box('orillacart-order-items', __('Order Items', 'com_shop'), array($this->view, 'order_items'), 'shop_order', 'advanced', 'high');
        add_meta_box('orillacart-order-totals', __('Order Totals', 'com_shop'), array($this->view, 'order_totals'), 'shop_order', 'side', 'high');
    }

    protected function fill_column() {



        if (!request::is_internal()) {
            return false;
        }
        $col = request::getWord('column');
        $id = request::getInt('id');



        $this->getView('orders');
        $model = $this->getModel('orders');
        $helper = Factory::getApplication('shop')->getHelper('order');

        $this->view->assign("helper", $helper);
        $this->view->assign("order", $helper->get_order($id));

        $this->view->assign('col', $col);
        $this->view->assign('id', $id);

        $this->view->setModel($model);
        parent::display('fill_column');
    }

    protected function delete() {

        if (!request::is_internal())
            return false;

        $model = $this->getModel('orders');

        $res = $model->delete_orders(request::getInt('id'));
    }

    protected function update_status() {

        $id = request::getInt('oid');
        $status = request::getString('status', null);



        Factory::getApplication('shop')->getHelper('order')->change_order_status($id, $status);
        die;


        //return $this->execute();
    }

    protected function save() {
        if (!request::is_internal()) {
            die();
        }

        $this->getModel('orders')->update();
    }

    protected function get_parent_list() {

        $this->getView('orders');
        $model = $this->getModel('orders');
        $this->view->setModel($model);
        parent::display('get_parent_list');
    }

    protected function get_users_list() {

        $this->getView('orders');
        $model = $this->getModel('orders');
        $this->view->setModel($model);
        parent::display('get_users_list');
    }

    protected function add_product_to_order() {

        $this->getView('orders');
        $model = $this->getModel('product_admin');
        $this->view->assign('all_attributes', (array) $model->getProductAttributes(request::getInt('pid', 0)));



        parent::display("add_product_form");
    }

    protected function save_product_to_order() {

        $this->getView('orders');

        $oid = request::getInt('oid');
        $pid = request::getInt('pid');
        $properties = array_map('intval', (array) request::getVar('property', array()));
        $qty = request::getInt('product_quantity', 1);
        $model = $this->getModel('orders');

        $status = '';

        try {
            $model->save_product_to_order($oid, $pid, $properties, $qty);
        } catch (Exception $e) {

            $status = $e->getMessage();
        }

        $this->view->assign('status', $status);

        $this->execute("reload_order_files_and_totals");
    }

    protected function reload_order_files_and_totals() {

        //load the view only if there is no internal redirect
        //from save_product_to_order method
        if (!$this->view) {
            $this->getView('orders');
        }
        $id = request::getInt('oid');

        $model = $this->getModel('orders');
        $order_helper = Helper::getInstance("order", "shop");
        $product = $this->getModel('product_admin');



        $order = $model->load_order($id);


        $this->view->assign("taxes",$order_helper->get_order_taxes($id));
        $this->view->setModel($product);
        $this->view->setModel($model);

        $price = Factory::getApplication('shop')->getHelper('price');
        $this->view->assign('price', $price);
        $this->view->assign('order', $order['order']);

        foreach ((array) $order['items'] as $k => $item) {
            $order['items'][$k] = $model->prepare_order_item($item);
        }


        $this->view->assign('items', (array) $order['items']);


        parent::display("reload_order_files_and_totals");
    }

}