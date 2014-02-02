<?php

class shopControllerCart extends controller {

    public function init() {

        if (Factory::getApplication('shop')->getParams()->get('catalogOnly', false)) {

            Factory::getApplication('shop')->redirect(Route::get("component=shop"));
        }
    }

    protected function display() {

        $cart = factory::getApplication('shop')->getHelper('cart');

        $update_errors = array();
        if (request::getMethod() == 'POST') {
            $qty = request::getVar('qty', array());

            foreach ((array) $qty as $k => $v) {

                if (!$cart->update_qty((int) $k, (int) $v)) {
                    $update_errors[] = $k;
                }
            }
        }

        if (!empty($update_errors)) {
            factory::getApplication('shop')->addError(__("Some quantities were not updated!", 'com_shop'));
        }


        $this->getView('cart');
        $this->view->assign('cart', $cart);
        $this->view->assign('update_errors', (array) $update_errors);

        parent::display();
    }

    protected function add_to_cart() {
        global $wp_query;
        if (isset($_SESSION['last_order_id'])) {
            unset($_SESSION['last_order_id']);
        }
        $response = array();

        $id = Request::getInt("id");
        $qty = Request::getInt('qty', 1);
        $model = $this->getModel('product');
        $cart = Factory::getApplication('shop')->getHelper('cart');

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
                    Factory::getApplication('shop')->close();
                } else {
                    Factory::getApplication('shop')->redirect(get_permalink($id));
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
                    $response['data'] = sprintf(__('Not enought quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res);
                } else {
                    $response['data'] = __('Chosen product is out of stock', 'com_shop');
                }
            } else {
                if ($res > 0) {
                    Factory::getApplication('shop')->addError(sprintf(__('Not enought quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res));
                } else {

                    Factory::getApplication('shop')->addError(__('Chosen product is out of stock', 'com_shop'));
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
            Factory::getApplication('shop')->close();
        }

        $this->view->assign('cart', $cart);
        parent::display();
    }

    protected function add_to_cart_custom() {
        if (isset($_SESSION['last_order_id'])) {
            unset($_SESSION['last_order_id']);
        }
        $this->getView('cart');
        $cart = Factory::getApplication('shop')->getHelper('cart');
        $response = array();
        $id = Request::getInt("id");
        $props = array();

        $props = (array) array_map('intval', (array) $_POST['property']);

        foreach ($props as $k => $v) {
            if (empty($v))
                unset($props[$k]);
        }

        $qty = Request::getInt('qty', 1);

        $model = $this->getModel('product');

        if (!$model->is_product($id)) {
            throw new not_found_404(__('no such product', 'com_shop'));
        }

        $res = $model->is_variation_available($id, (array) $props);

        if ($res >= $qty || $res === true) {

            $files = array();

            if (has_term('digital', 'product_type', (int) $id)) {
                $row = Factory::getApplication('shop')->getTable('product')->load((int) $id);
                $files = request::getVar('files', array());

                if (!$row->download_choose_file) {
                    $files = null;
                }

                if (empty($files) && $files !== null) {
                    Factory::getApplication('shop')->add_custom_error('product_digital_files', __('Please select option!', 'com_shop'));
                    Factory::getApplication('shop')->addError(__('Please select option!', 'com_shop'));
                    if (Request::is_ajax()) {
                        $response['action'] = 'redirect';
                        $response['data'] = get_permalink($id);

                        header("HTTP/1.0 200 OK");
                        header('Content-type: text/json; charset=utf-8');
                        header("Cache-Control: no-cache, must-revalidate");
                        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                        header("Pragma: no-cache");
                        echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
                        Factory::getApplication('shop')->close();
                    } else {
                        Factory::getApplication('shop')->redirect(get_permalink($id));
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
                    Factory::getApplication('shop')->close();
                } else {
                    Factory::getApplication('shop')->redirect(get_permalink($id));
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
                    $response['data'] = sprintf(__('Not enought quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res);
                } else {
                    $response['data'] = __('Chosen product is out of stock', 'com_shop');
                }

                header("HTTP/1.0 200 OK");
                header('Content-type: text/json; charset=utf-8');
                header("Cache-Control: no-cache, must-revalidate");
                header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
                header("Pragma: no-cache");
                echo json_encode(apply_filters('orillacart_add_to_cart_json', $response));
                Factory::getApplication('shop')->close();
            } else {
                if ($res > 0) {
                    Factory::getApplication('shop')->addError(sprintf(__('Not enought quantity in stock. You can buy maximum of %s items.', 'com_shop'), $res));
                } else {

                    Factory::getApplication('shop')->addError(__('Chosen product is out of stock', 'com_shop'));
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
            Factory::getApplication('shop')->close();
        }

        $this->view->assign('cart', $cart);
        parent::display();
    }

    protected function remove() {

        $this->getView('cart');
        $cart = Factory::getApplication('shop')->getHelper('cart');

        $cart->remove(Request::getInt('group', null));

        $this->view->assign('cart', $cart);
        parent::display();
    }

    protected function checkout() {

        $cart = Factory::getApplication('shop')->getHelper('cart');

        if ($cart->is_empty() && !Request::is_ajax()) {
            if (isset($_SESSION['last_order_id'])) {
                Request::setVar('order_id', $_SESSION['last_order_id']);
                return $this->execute('process_payment');
            }
            Factory::getApplication('shop')->addError(__('Your cart is empty. Please add some products before continue!', 'com_shop'));
            return $this->execute();
        }
        if (request::is_ajax() && isset($_POST['update_totals'])) {
            $post = null;
            parse_str($_POST['post_data'], $post);
            request::set($post, 'POST');
        }

        $customer = Factory::getApplication('shop')->getHelper('customer');

        $order_comments = request::getString('order_comments', '');

        if (request::getMethod() == 'POST') {
            if ($cart->need_shipping()) {
                if (!request::getString('shipping_method', null)) {
                    Factory::getApplication('shop')->addError(__("Choose shipping method!", 'com_shop'), false);
                } else {
                    if (!$cart->set_shipping(request::getString('shipping_method', null))) {
                        Factory::getApplication('shop')->addError(__("Invalid shipping method!", 'com_shop'), false);
                    }
                }
            }

            if ($cart->need_payment()) {
                if (!request::getString('payment_method', null)) {
                    Factory::getApplication('shop')->addError(__("Choose payment method!", 'com_shop'), false);
                } else {
                    if (!$cart->set_payment(request::getString('payment_method', null))) {
                        Factory::getApplication('shop')->addError(__("Invalid payment method!", 'com_shop'), false);
                    }
                }
            }
        } else {
            if (isset($_SESSION['last_order_id']))
                unset($_SESSION['last_order_id']);
        }

        if (Factory::getApplication('shop')->errorsCount() > 0 || request::getMethod() != 'POST' || isset($_POST['update_totals'])) {
            $this->getView('cart');
            $this->view->assign('payment_methods', (array) $cart->get_payment_methods());
            $this->view->assign('cart', $cart);
            $this->view->assign("taxes", $cart->get_cart_taxes());


            $this->view->assign('ship_to_billing', $customer->ship_to_billing());
            $this->view->assign('order_comments', $order_comments);
            $this->view->assign('shipping_methods', (array) $cart->get_shipping_rates());

            $user_name = isset($_POST['account']['username']) ? $_POST['account']['username'] : '';

            $this->view->assign('user_name', $user_name);
            $this->view->assign('billing', $customer->get_billing());
            $this->view->assign('shipping', $customer->get_shipping());
            $this->view->assign('params', Factory::getApplication('shop')->getParams());

            if (isset($_POST['update_totals'])) {
                Factory::getApplication('shop')->setMessage(__("Totals has been updated.", 'com_shop'), false);
            }

            if (Request::is_ajax()) {
                return parent::display('update_totals');
            }
        } else {
            $reg_type = Factory::getApplication('shop')->getParams()->get('userReg');

            if (( request::getBool('createaccount', false) || $reg_type == 1 ) && !get_current_user_id()) {
                try {
                    $acc = request::getVar('account', array(), 'POST');
                    $acc['email'] = request::getString('billing_email', null, 'POST');
                    $customer->create_user($acc);
                } catch (Exception $e) {
                    Factory::getApplication('shop')->addError($e->getMessage(), false);
                    return $this->execute('checkout');
                }

                if ($reg_type == 1 && !get_current_user_id()) {
                    Factory::getApplication('shop')->addError(__("Please login or register to proceed.", 'com_shop'), false);
                    return $this->execute('checkout');
                }
            }
            try {
                $res = true;
                foreach (array('billing', 'shipping') as $type) {
                    if ($type == 'billing') {
                        $fields = $customer->get_billing();
                    } else {
                        if ($customer->ship_to_billing())
                            continue;
                        $fields = $customer->get_shipping();
                    }

                    while ($f = $fields->get_field()) {
                        if (!$f->validate()) {
                            $res = false;
                            if (!$customer->ship_to_billing()) {
                                Factory::getApplication('shop')->addError("(" . $type . ")" . $f->get_error_msg(), false);
                            } else {
                                Factory::getApplication('shop')->addError($f->get_error_msg(), false);
                            }
                        }
                    }
                }

                if (!$res) {
                    return $this->execute('checkout');
                }
            } catch (Exception $e) {
                Factory::getApplication('shop')->addError($e->getMessage(), false);
                return $this->execute('checkout');
            }
            return $this->process_checkout();
        }
        parent::display('checkout');
    }

    protected function load_states() {

        $this->getView('cart');
        $model = $this->getModel("country");

        $id = Request::getString('country', null);
        $type = strtolower(Request::getWord('type', 'billing'));
        if (!in_array($type, array('billing', 'shipping'))) {
            $type = 'billing';
        }
        echo field::_('state', $type . '_state')->set_country($id)->render();
        exit;
    }

    private function process_checkout() {

        $cart = Factory::getApplication('shop')->getHelper('cart');
        $model = $this->getModel('order');

        if (isset($_SESSION['last_order_id']) && $_SESSION['last_order_id']) {
            request::setVar('order_id', $_SESSION['last_order_id']);
            return $this->execute('process_payment');
        }

        if ($cart->need_payment()) {
            $class = $cart->selected_payment_method()->get_class_name();
            if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
                Factory::getApplication('shop')->addError(__('Payment gateway class not found!', 'com_shop'));
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
            Factory::getApplication('shop')->addError(__('Failed to store the order, try again!', 'com_shop'));
            return $this->execute('checkout');
        }

        $_SESSION['last_order_id'] = $oid;
        request::setVar('order_id', $_SESSION['last_order_id']);
//give directly completed status if order does not need payment
//e.x. all products are free and shipping is free also.
        if (!$cart->need_payment()) {
            $status = 'completed';
        }

        $cart->empty_cart();
        $helper = Factory::getApplication('shop')->getHelper('order');

//setting default order status as on-hold or pending 
//depending on the type of the gateway used
        $helper->change_order_status($oid, $status);
//order is stored and we move on to the actual payment    
        return $this->execute('process_payment');
    }

    protected function cancel_order() {

        $helper = Factory::getApplication('shop')->getHelper('order');

        $oid = Request::getInt('order_id', null);
        $row = $helper->get_order($oid);
        if (!has_term(array('pending', 'failed'), 'order_status', (int) $oid)) {

            wp_die(__("Only not completed orders can be cancelled! Please contact us for additional information!", 'com_shop'));
            exit;
        }

        $key = Request::getString("order_key", null);

        if (!$key || $key != $row['post_password']) {
            wp_die(__("The key provided for that order is incorrect!", 'com_shop'));
            exit;
        }

        $helper->change_order_status($oid, 'cancelled');
        wp_die(sprintf(__("Order #%s has been cancelled!", 'com_shop'), $oid));
        exit;
    }

    protected function process_payment() {

        $error = Request::getString("gateway_error", '');
        $msg = Request::getString("gateway_msg", '');
        if (!empty($error)) {
            Factory::getApplication('shop')->addError($error);
        }
        if (!empty($msg)) {
            Factory::getApplication('shop')->addMessage($msg);
        }

        $order = Factory::getApplication('shop')->getHelper('order');
        $oid = Request::getInt('order_id', null);
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
            $key = Request::getString("order_key", null);

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
                if ($model->validate_card($gateway) && !Factory::getApplication('shop')->errorsCount()) {
                    try {
                        $model->process_credit_card($gateway, $oid);
                    } catch (gateway_exception $e) {
                        Factory::getApplication('shop')->addError($e->getMessage());
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
                $helper = Factory::getApplication('shop')->getHelper('order');

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
        $helper = Factory::getApplication('shop')->getHelper('order');
        $class = request::getString('gateway', null);

        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            Factory::getApplication('shop')->addError(__("missing gateway method in the notify request!", 'com_shop'));
            return $this->execute('order_details');
        }

        $gateway = new $class();
        $res = $gateway->handle_notify();


        if (!empty($res->msg)) {
            Factory::getApplication('shop')->setMessage($res->msg);
        }
        if (!empty($res->order_status) && !empty($res->order_id)) {
            $helper->change_order_status($res->order_id, $res->order_status);
        }

        if (empty($res->order_id)) {
            Factory::getApplication('shop')->addError(__("missing order id!", 'com_shop'));
        } else {
            request::setVar('order_id', $res->order_id);
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

        $error = Request::getString("gateway_error", '');
        $msg = Request::getString("gateway_msg", '');
        if (!empty($error)) {
            Factory::getApplication('shop')->addError($error);
        }
        if (!empty($msg)) {
            Factory::getApplication('shop')->setMessage($msg);
        }

        $helper = Factory::getApplication('shop')->getHelper('order');
        $order = $helper->get_order(request::getInt('order_id', null));
        $price = Factory::getApplication('shop')->getHelper('price');
        $model = $this->getModel('order');
        $this->getView('cart');
        if (!$order) {
            wp_die(__("Invalid order id!", 'com_shop'));
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
        $this->view->assign("taxes",$helper->get_order_taxes($order['ID']));
        parent::display('order_receipt');
    }

    protected function gateway_ajax() {
        for ($c = 0; $c < ob_get_level(); $c++)
            ob_end_clean();
        $class = request::getString('gateway', null);
        $method = request::getCmd('method', 'ajax');

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