<?php

class shopControllerCart extends controller {

    public function init() {

        if (Factory::getComponent('shop')->getParams()->get('catalogOnly', false)) {

            Factory::getComponent('shop')->redirect(Route::get("component=shop"));
        }
    }

    protected function __default() {

        $cart = Factory::getComponent('shop')->getHelper('cart');
        $input = Factory::getApplication()->getInput();

        $update_errors = array();
        if ($input->getMethod() == 'POST') {
            $qty = $input->get('qty', array(), "ARRAY");

            foreach ((array) $qty as $k => $v) {

                if (!$cart->update_qty((int) $k, (int) $v)) {
                    $update_errors[] = $k;
                }
            }
        }

        if (!empty($update_errors)) {
            Factory::getComponent('shop')->addError(__("Some quantities were not updated!", 'com_shop'));
        }


        $this->getView('cart');
        $this->view->assign('cart', $cart);
        $this->view->assign('update_errors', (array) $update_errors);

        parent::display();
    }

    protected function add_to_cart() {
        global $wp_query;

        $input = Factory::getApplication()->getInput();

        if (isset($_SESSION['last_order_id'])) {
            unset($_SESSION['last_order_id']);
        }
        $response = array();

        $id = $input->get("id", 0, "INT");
        $qty = $input->get('qty', 1, "INT");
        $model = $this->getModel('product');
        $cart = Factory::getComponent('shop')->getHelper('cart');

        $this->getView('cart');
        if (!$model->is_product($id)) {

            throw new not_found_404('no such product');
        }

        if (has_term('digital', 'product_type', (int) $id)) {
            return $this->execute('add_to_cart_custom');
        }

        $atts = $model->getProductAttributes($id);

        $res = $model->is_variation_available($id);

        if ($res >= $qty || $res === true) {
            do_action('orillacart_before_add_to_cart', $id);
            if (!$cart->add_to_cart($id, $qty)) {
                if (Request::is_ajax()) {
                    $response['action'] = 'redirect';
                    $response['data'] = get_permalink($id);

                    header("HTTP/1.0 200 OK");
                    header('Content-type: text/json; charset=utf-8');
                    header("Cache-Control: no-cache, must-revalidate");
                    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                    header("Pragma: no-cache");
                    echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
                    Factory::getComponent('shop')->close();
                } else {
                    Factory::getComponent('shop')->redirect(get_permalink($id));
                    exit;
                }
            } else {
                if (Request::is_ajax()) {
                    $response['action'] = 'msg';
                    $response['data'] = __('product added in the cart', 'com_shop');
                }
            }
            do_action('orillacart_after_add_to_cart', $id);
        } else {
            if (Request::is_ajax()) {
                $response['action'] = 'msg';

                if ($res > 0) {
                    $response['data'] = sprintf(__('Not enough quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res);
                } else {
                    $response['data'] = __('Chosen product is out of stock', 'com_shop');
                }
            } else {
                if ($res > 0) {
                    Factory::getComponent('shop')->addError(sprintf(__('Not enough quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res));
                } else {

                    Factory::getComponent('shop')->addError(__('Chosen product is out of stock', 'com_shop'));
                }
            }
        }

//we have ajax request for add to cart so return json value
        if (Request::is_ajax()) {
            header("HTTP/1.0 200 OK");
            header('Content-type: text/json; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");
            echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
            Factory::getComponent('shop')->close();
        }

        $this->view->assign('cart', $cart);
        parent::display();
    }

    protected function add_to_cart_custom() {

        $input = Factory::getApplication()->getInput();

        if (isset($_SESSION['last_order_id'])) {
            unset($_SESSION['last_order_id']);
        }
        $this->getView('cart');
        $cart = Factory::getComponent('shop')->getHelper('cart');
        $response = array();
        $id = $input->get("id", 0, "INT");


        if (isset($_POST['property'])) {
            $props = (array) array_map('intval', (array) $_POST['property']);

            foreach ($props as $k => $v) {
                if (empty($v))
                    unset($props[$k]);
            }
        }else {

            $props = array();
        }

        $qty = $input->get('qty', 1, "INT");

        $model = $this->getModel('product');

        if (!$model->is_product($id)) {
            throw new not_found_404(__('no such product', 'com_shop'));
        }

        $res = $model->is_variation_available($id, (array) $props);

        if ($res >= $qty || $res === true) {

            $files = array();

            if (has_term('digital', 'product_type', (int) $id)) {
                $row = Factory::getComponent('shop')->getTable('product')->load((int) $id);
                $files = $input->get('files', array(), "ARRAY");

                if (!$row->download_choose_file) {
                    $files = null;
                }

                if (empty($files) && $files !== null) {
                    Factory::getComponent('shop')->addCustomError('product_digital_files', __('Please select option!', 'com_shop'));
                    Factory::getComponent('shop')->addError(__('Please select option!', 'com_shop'));
                    if (Request::is_ajax()) {
                        $response['action'] = 'redirect';
                        $response['data'] = get_permalink($id);

                        header("HTTP/1.0 200 OK");
                        header('Content-type: text/json; charset=utf-8');
                        header("Cache-Control: no-cache, must-revalidate");
                        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                        header("Pragma: no-cache");
                        echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
                        Factory::getComponent('shop')->close();
                    } else {
                        Factory::getComponent('shop')->redirect(get_permalink($id));
                        exit;
                    }
                }
            }

            do_action('orillacart_before_add_to_cart', $id);

            if (!$cart->add_to_cart($id, $qty, (array) $props, $files)) {
                if (Request::is_ajax()) {
                    $response['action'] = 'redirect';
                    $response['data'] = get_permalink($id);

                    header("HTTP/1.0 200 OK");
                    header('Content-type: text/json; charset=utf-8');
                    header("Cache-Control: no-cache, must-revalidate");
                    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                    header("Pragma: no-cache");
                    echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
                    Factory::getComponent('shop')->close();
                } else {
                    Factory::getComponent('shop')->redirect(get_permalink($id));
                }
                exit;
            } else {
                if (Request::is_ajax()) {
                    $response['action'] = 'msg';
                    $response['data'] = __('product added in the cart', 'com_shop');
                }
            }
            do_action('orillacart_after_add_to_cart', $id);
        } else {
            if (Request::is_ajax()) {
                $response['action'] = 'msg';
                if ($res > 0) {
                    $response['data'] = sprintf(__('Not enough quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res);
                } else {
                    $response['data'] = __('Chosen product is out of stock', 'com_shop');
                }

                header("HTTP/1.0 200 OK");
                header('Content-type: text/json; charset=utf-8');
                header("Cache-Control: no-cache, must-revalidate");
                header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                header("Pragma: no-cache");
                echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
                Factory::getComponent('shop')->close();
            } else {
                if ($res > 0) {
                    Factory::getComponent('shop')->addError(sprintf(__('Not enough quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res));
                } else {

                    Factory::getComponent('shop')->addError(__('Chosen product is out of stock', 'com_shop'));
                }
            }
        }

        if (Request::is_ajax()) {

            header("HTTP/1.0 200 OK");
            header('Content-type: text/json; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");
            echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
            Factory::getComponent('shop')->close();
        }

        $this->view->assign('cart', $cart);
        parent::display();
    }

    protected function remove() {

        $input = Factory::getApplication()->getInput();
        $this->getView('cart');
        $cart = Factory::getComponent('shop')->getHelper('cart');

        $cart->remove($input->get('group', null, "INT"));


        wp_safe_redirect(Route::get("component=shop&con=cart"));
        exit;
    }

    protected function checkout() {

        $cart = Factory::getComponent('shop')->getHelper('cart');
        $input = Factory::getApplication()->getInput();
        $filter = FilterInput::getInstance();

        if ($cart->is_empty() && !Request::is_ajax()) {
            if (isset($_SESSION['last_order_id'])) {
                $input->set('order_id', $_SESSION['last_order_id']);
                return $this->execute('process_payment');
            }
            Factory::getComponent('shop')->addError(__('Your cart is empty. Please add some products before continue!', 'com_shop'));
            return $this->execute();
        }
        if (request::is_ajax() && isset($_POST['update_totals'])) {
            $post = null;

            parse_str($input->get('post_data', "", "STRING"), $post);
            $input->set($post);
			$input->post->set($post);
        }

        $customer = Factory::getComponent('shop')->getHelper('customer');

        $order_comments = $input->get('order_comments', '', "STRING");

        if ($input->getMethod() == 'POST') {
            if ($cart->need_shipping()) {
                if (!$input->get('shipping_method', null, "STRING")) {
                    Factory::getComponent('shop')->addError(__("Choose shipping method!", 'com_shop'), false);
                } else {
                    if (!$cart->set_shipping($input->get('shipping_method', null, "STRING"))) {
                        Factory::getComponent('shop')->addError(__("Invalid shipping method!", 'com_shop'), false);
                    }
                }
            }

            if ($cart->need_payment()) {
                if (!$input->get('payment_method', null, "STRING")) {
                    Factory::getComponent('shop')->addError(__("Choose payment method!", 'com_shop'), false);
                } else {
                    if (!$cart->set_payment($input->get('payment_method', null, "STRING"))) {
                        Factory::getComponent('shop')->addError(__("Invalid payment method!", 'com_shop'), false);
                    }
                }
            }
        } else {
            if (isset($_SESSION['last_order_id']))
                unset($_SESSION['last_order_id']);
        }

        if (Factory::getComponent('shop')->errorsCount() > 0 || $input->getMethod() != 'POST' || isset($_POST['update_totals'])) {
            $this->getView('cart');
            $this->view->assign('payment_methods', (array) $cart->get_payment_methods());
            $this->view->assign('cart', $cart);
            $this->view->assign("taxes", $cart->get_cart_taxes());


            $this->view->assign('ship_to_billing', $customer->ship_to_billing());
            $this->view->assign('order_comments', $order_comments);
            $this->view->assign('shipping_methods', (array) $cart->get_shipping_rates());



            $user_name = isset($input->post['account']['username']) ? $filter->clean($input->post['account']['username'], "USERNAME") : '';



            $this->view->assign('user_name', $user_name);
            $this->view->assign('billing', $customer->get_billing());
            $this->view->assign('shipping', $customer->get_shipping());
            $this->view->assign('params', Factory::getComponent('shop')->getParams());

            if (isset($_POST['update_totals'])) {
                Factory::getComponent('shop')->setMessage(__("Totals has been updated.", 'com_shop'), false);
            }

            if (Request::is_ajax()) {
                return parent::display('update_totals');
            }
        } else {
            $reg_type = Factory::getComponent('shop')->getParams()->get('userReg');

            if (( $input->get('createaccount', false, "BOOL") || $reg_type == 1 ) && !get_current_user_id()) {
                try {
                    $acc = $input->get('account', array(), "ARRAY");
                    $acc['email'] = $input->get('billing_email', null, 'STRING');
                    $customer->create_user($acc);
                } catch (Exception $e) {
                    Factory::getComponent('shop')->addError($e->getMessage(), false);
                    return $this->execute('checkout');
                }

                if ($reg_type == 1 && !get_current_user_id()) {
                    Factory::getComponent('shop')->addError(__("Please login or register to proceed.", 'com_shop'), false);
                    return $this->execute('checkout');
                }
            }
            try {
                $res = true;
                foreach (array('billing', 'shipping') as $type) {
                    if ($type == 'billing') {
                        $fields = $customer->get_billing();
                    } else {
                        if ($customer->ship_to_billing() || !Factory::getComponent("shop")->getParams()->get("shipping"))
                            continue;
                        $fields = $customer->get_shipping();
                    }

                    while ($f = $fields->get_field()) {
                        if (!$f->validate()) {
                            $res = false;
                            if (!$customer->ship_to_billing()) {
                                Factory::getComponent('shop')->addError("(" . $type . ")" . $f->get_error_msg(), false);
                            } else {
                                Factory::getComponent('shop')->addError($f->get_error_msg(), false);
                            }
                        }
                    }
                }

                if (!$res) {
                    return $this->execute('checkout');
                }
            } catch (Exception $e) {
                Factory::getComponent('shop')->addError($e->getMessage(), false);
                return $this->execute('checkout');
            }
            return $this->process_checkout();
        }
        parent::display('checkout');
    }

    protected function load_states() {

        $input = Factory::getApplication()->getInput();
        $this->getView('cart');
        $model = $this->getModel("country");

        $id = $input->get('country', null, "STRING");

		
		
        $type = strtolower($input->get('type', 'billing', "WORD"));
        if (!in_array($type, array('billing', 'shipping'))) {
            $type = 'billing';
        }




        echo field::_('state', $type . '_state')->set_country($id)->add_class('form-control')->render();
        exit;
    }

    private function process_checkout() {

        $cart = Factory::getComponent('shop')->getHelper('cart');
        $model = $this->getModel('order');
        $input = Factory::getApplication()->getInput();

        if (isset($_SESSION['last_order_id']) && $_SESSION['last_order_id']) {
            $input->set('order_id', $_SESSION['last_order_id']);
            return $this->execute('process_payment');
        }

        if ($cart->need_payment()) {
            $class = $cart->selected_payment_method()->get_class_name();
            if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
                Factory::getComponent('shop')->addError(__('Payment gateway class not found!', 'com_shop'));
                return $this->execute('checkout');
            }

            $gateway = new $class();
            $type = $gateway->get_type();

            switch ($type) {
                case payment_method::ccard :
                    $status = 'pending';
                    break;
                case payment_method::form :
                    $status = 'pending';
                    break;
                case payment_method::pod :
                default:
                    $status = 'on-hold';
                    break;
            }
        }

        $oid = $model->store_order();
        do_action('orillacart_send_order_invoice', $oid);

        if (!$oid) {
            Factory::getComponent('shop')->addError(__('Failed to store the order, try again!', 'com_shop'));
            return $this->execute('checkout');
        }

        $_SESSION['last_order_id'] = $oid;
        $input->set('order_id', $_SESSION['last_order_id']);
//give directly completed status if order does not need payment
//e.x. all products are free and shipping is free also.
        if (!$cart->need_payment()) {
            $status = 'completed';
        }

        $cart->empty_cart();
        $helper = Factory::getComponent('shop')->getHelper('order');

//setting default order status as on-hold or pending 
//depending on the type of the gateway used
        $helper->change_order_status($oid, $status);
//order is stored and we move on to the actual payment    
        return $this->execute('process_payment');
    }

    protected function cancel_order() {

        $helper = Factory::getComponent('shop')->getHelper('order');
        $input = Factory::getApplication()->getInput();

        $oid = $input->get('order_id', null, "INT");
        $row = $helper->get_order($oid);
        if (!has_term(array('pending', 'failed'), 'order_status', (int) $oid)) {
			
			$message = __("Only not completed orders can be cancelled! Please contact us for additional information!", 'com_shop');
			$message .="<br />";
			$message .="<a href=".Route::get("component=shop").">".__("Back to the store.","com_shop")."</a>";
			
            wp_die($message);
            exit;
        }

        $key = $input->get("order_key", null, "STRING");

        if (!$key || $key != $row['post_password']) {
            wp_die(__("The key provided for that order is incorrect!", 'com_shop'));
            exit;
        }

        $helper->change_order_status($oid, 'cancelled');
        wp_die(sprintf(__("Order #%s has been cancelled!", 'com_shop'), $oid));
        exit;
    }

    protected function process_payment() {

        $input = Factory::getApplication()->getInput();

        $error = $input->get("gateway_error", '', "STRING");
        $msg = $input->get("gateway_msg", '', "STRING");
        if (!empty($error)) {
            Factory::getComponent('shop')->addError($error);
        }
        if (!empty($msg)) {
            Factory::getComponent('shop')->addMessage($msg);
        }

        $order = Factory::getComponent('shop')->getHelper('order');
        $oid = $input->get('order_id', null, "INT");
        $row = $order->get_order($oid);

        if (!$row) {
            wp_die(__("Invalid order id!", 'com_shop'));
            exit;
        }

        $can_pay = false;

// the customer is paying newely created order, so no validation is required
        if (isset($_SESSION['last_order_id']) && $_SESSION['last_order_id'] == $oid) {
            $can_pay = true;
        } else {
//validate is that order really belongs to that customer
            $key = $input->get("order_key", "", "STRING");

            if ($key && $key == $row['post_password'])
                $can_pay = true;
            $_SESSION['last_order_id'] = $oid;
        }

        if (!$can_pay) {
            wp_die(__("Invalid order key!", 'com_shop'));
            exit;
        }

        if (has_term(array('completed', 'shipped'), 'order_status', (int) $oid)) {
            return $this->execute('order_details');
        }

        $model = $this->getModel('order');
        $class = $row['payment_method'];

        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            throw new Exception(__("Payment class missing!", 'com_shop'));
        }
        $gateway = new $class();
        $type = $gateway->get_type();

        switch ($type) {
            case payment_method::ccard :
                if ($model->validate_card($gateway) && !Factory::getComponent('shop')->errorsCount()) {
                    try {
                        $model->process_credit_card($gateway, $oid);
                    } catch (gateway_exception $e) {
                        Factory::getComponent('shop')->addError($e->getMessage());
                        return $this->execute('process_payment');
                    }
                    return $this->execute('order_details');
                } else {
                    $this->getView('cart');

                    $this->view->assign('require_cvv', $gateway->require_cvv());
                    $this->view->assign('require_ctype', $gateway->require_ctype());
                    $this->view->assign('cards', $gateway->get_cards_list());
                    return parent::display('ccard_form');
                }
                break;

            case payment_method::form :
            case payment_method::pod :
            default:
                $helper = Factory::getComponent('shop')->getHelper('order');

                if ($type != payment_method::pod) {
                    $this->getView('cart');
                    $this->view->assign('gateway', $gateway);
                    $this->view->assign('order_id', $oid);
                    $this->view->assign('order', $row);
                    parent::display('do_payment');
                } else {
                    return $this->execute('order_details');
                }
                break;
        }
    }

    protected function gateway_notify() {

        do_action('before_gateway_notify');
        $model = Model::getInstance("order", "shop");
        $input = Factory::getApplication()->getInput();

        $helper = Factory::getComponent('shop')->getHelper('order');

        $class = $input->get('gateway', null, "STRING");

        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            Factory::getComponent('shop')->addError(__("missing gateway method in the notify request!", 'com_shop'));
            return $this->execute('order_details');
        }

        $gateway = new $class();
        $res = $gateway->handle_notify();


        if (!empty($res->msg)) {
            Factory::getComponent('shop')->setMessage($res->msg);
        }
        if (!empty($res->order_status) && !empty($res->order_id)) {
            $helper->change_order_status($res->order_id, $res->order_status);
        }

        if (empty($res->order_id)) {
            Factory::getComponent('shop')->addError(__("missing order id!", 'com_shop'));
        } else {
            $input->set('order_id', $res->order_id);
        }

        if (property_exists($res, "tid") && !empty($res->tid)) {
            try {
                $model->update_order_tid($res->order_id, $res->tid);
            } catch (Exception $e) {
                //if there is error storing transaction id do nothing
            }
        }
        do_action("after_gateway_notify", $res, $res->order_status);
        if (method_exists($gateway, 'on_after_gateway_notify')) {
            $gateway->on_after_gateway_notify($res, $res->order_status);
        }
        return $this->execute('order_details');
    }

    protected function order_details() {

        $input = Factory::getApplication()->getInput();

        $error = $input->get("gateway_error", '', "STRING");
        $msg = $input->get("gateway_msg", '', "STRING");
        if (!empty($error)) {
            Factory::getComponent('shop')->addError($error);
        }
        if (!empty($msg)) {
            Factory::getComponent('shop')->setMessage($msg);
        }

        $helper = Factory::getComponent('shop')->getHelper('order');
        $order = $helper->get_order($input->get('order_id', null, "INT"));
        $price = Factory::getComponent('shop')->getHelper('price');
        $model = $this->getModel('order');
        $this->getView('cart');
        if (!$order) {
            wp_die(__("Invalid order id!", 'com_shop'));
        }
		
		$key = $input->get('order_key', null, "STRING");

        if ($key !== $order['post_password'] && (!isset($_SESSION['last_order_id']) || $_SESSION['last_order_id'] !=  $order['ID'])) {
            wp_die(__("Authentication failed", 'com_shop'));
            exit;
        }

        $class = $order['payment_method'];
        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            $this->view->assign('on_receipt_content', '');
        } else {
            $gateway = new $class();
            if (method_exists($gateway, 'on_receipt')) {
                $this->view->assign('on_receipt_content', (string) $gateway->on_receipt());
            } else {
                $this->view->assign('on_receipt_content', '');
            }
        }

        $this->view->assign("files", $model->get_order_files((int) $order['ID']));
        $this->view->assign('price', $price);
        $this->view->assign('items', $model->get_order_items($order['ID']));
        $this->view->assign('order', $order);
        $this->view->assign("taxes", $helper->get_order_taxes($order['ID']));
        parent::display('order_receipt');
    }

    protected function gateway_ajax() {
        for ($c = 0; $c < ob_get_level(); $c++)
            ob_end_clean();

        $input = Factory::getApplication()->getInput();

        $class = $input->get('gateway', null, "STRING");
        $method = $input->get('method', 'ajax', "CMD");

        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            exit;
        }

        $gateway = new $class();
        if (method_exists($gateway, $method)) {
            $gateway->{$method}();
        }
        exit;
    }

}
