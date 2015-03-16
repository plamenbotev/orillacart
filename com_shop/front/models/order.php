<?php

class orderModel extends model {

    public function validate_card(card_method $gateway) {

        $input = Factory::getApplication()->getInput();

        $card_holder = $input->get('card_holder_name', null, "STRING");
        $card_no = $input->get('card_number', null, "STRING");
        $card_expire_month = $input->get('card_expire_month', 0, "INT");
        $card_expire_year = $input->get('card_expire_year', 0, "INT");
        $card_cvv = $input->get('card_code', null, "STRING");
        $card_type = $input->get('card_type', null, "STRING");



        if (!isset($_POST['ccard_form'])) {
            return false;
        }

        if (empty($card_holder) || preg_match("/[0-9]+/", $card_holder) == true) {

            Factory::getComponent('shop')->addError(__('please fill card holder name!', 'com_shop'));
            return false;
        }


        if (!ctype_digit($card_no)) {
            Factory::getComponent('shop')->addError(__('please fill valid card number!', 'com_shop'));
            return false;
        }

        if ($gateway->require_ctype()) {
            if (!is_object($gateway->get_card($card_type)) || !$gateway->get_card($card_type)->validate($card_no)) {
                Factory::getComponent('shop')->addError(__('please fill valid card number!', 'com_shop'));
                return false;
            }
        }

        if (!$card_expire_month) {
            Factory::getComponent('shop')->addError(__('please fill card expire month!', 'com_shop'));
            return false;
        }

        if (!$card_expire_year) {
            Factory::getComponent('shop')->addError(__('please fill card expire year!', 'com_shop'));
            return false;
        }
        if ($gateway->require_cvv()) {
            if (!ctype_digit($card_cvv) || strlen($card_cvv) < 3 || strlen($card_cvv) > 4) {
                Factory::getComponent('shop')->addError(__('please fill card code!', 'com_shop'));
                return false;
            }
        }
        return true;
    }

    public function get_order_items($id) {


        $items = array();

        $this->db->setQuery("SELECT * FROM #_shop_order_item WHERE order_id =  " . (int) $id);
        $db = Factory::getDBO();

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        while ($o = $this->db->nextObject()) {


            $o->props = array();

            $db->setQuery("SELECT * FROM `#_shop_order_attribute_item` WHERE order_item_id = {$o->order_item_id} AND section='property'");

            if (!$this->db->getResource()) {
                throw new Exception($this->db->getErrorString());
            }

            while ($p = $db->nextObject()) {
                $o->props[] = $p;
            }

            $items[] = $o;
        }
        return $items;
    }

    public function get_customer_files($uid) {

        $this->db->setQuery("SELECT oi.*,o.*,ai.*, 
            (SELECT COUNT(*) FROM #_shop_order_attribute_item WHERE section ='file' AND order_id = o.ID) as order_files_count,
            (SELECT COUNT(*) FROM #_shop_order_attribute_item WHERE section ='file' AND order_id = o.ID AND order_item_id = oi.order_item_id) as order_item_files_count

                             FROM #_posts as o
                             INNER JOIN #_postmeta as u ON u.post_id = o.ID AND meta_key = '_customer_id'
                             INNER JOIN #_shop_order_item as oi ON oi.order_id = o.ID
                             INNER JOIN #_shop_order_attribute_item as ai ON ai.order_item_id = oi.order_item_id
                             WHERE u.meta_value = " . (int) $uid . " AND o.post_type = 'shop_order' AND ai.section = 'file' AND  oi.product_type = 'digital' ORDER BY oi.order_id DESC, oi.order_item_id DESC ");


        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }


        $rows = array();

        while ($row = $this->db->nextObject()) {

            $file = new stdClass();


            $file->order_files_count = (int) $row->order_files_count;
            $file->order_item_files_count = (int) $row->order_item_files_count;
            $file->order_id = $row->order_id;
            $file->product_name = $row->order_item_name;

            $file->item_id = $row->order_item_id;
            $file->file_id = $row->section_id;
            $file->order_key = $row->post_password;
            $file->customer_email = $row->customer_email;

            $rows[] = $file;
        }

        return $rows;
    }

    public function get_order_files($id) {

        $this->db->setQuery("SELECT oi.*,o.*,ai.*, 
            (SELECT COUNT(*) FROM #_shop_order_attribute_item WHERE section ='file' AND order_id = o.ID AND order_item_id = oi.order_item_id) as order_item_files_count

                             FROM #_posts as o
                          
                             INNER JOIN #_shop_order_item as oi ON oi.order_id = o.ID
                             INNER JOIN #_shop_order_attribute_item as ai ON ai.order_item_id = oi.order_item_id
                             WHERE o.post_type = 'shop_order' AND ai.section = 'file' AND  oi.product_type = 'digital' AND o.ID = " . (int) $id . " ORDER BY oi.order_item_id DESC ");

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }


        $rows = array();

        while ($row = $this->db->nextObject()) {

            $file = new stdClass();

            $file->order_item_files_count = (int) $row->order_item_files_count;
            $file->order_id = $row->order_id;
            $file->product_name = $row->order_item_name;

            $file->item_id = $row->order_item_id;
            $file->file_id = $row->section_id;
            $file->order_key = $row->post_password;
            $file->customer_email = $row->customer_email;

            $rows[] = $file;
        }

        return $rows;
    }

    public function store_order() {


        $cart = Factory::getComponent('shop')->getHelper('cart');
        $customer = Factory::getComponent('shop')->getHelper('customer');
        $h_order = Factory::getComponent('shop')->getHelper("order");

        $this->db->startTransaction();

        $order_dim = $cart->getCartItemDimention();

        $order = Factory::getComponent('shop')->getTable('order');

        $input = Factory::getApplication()->getInput();

        if (is_user_logged_in()) {
            $order->customer_id = get_current_user_id();
        }
        $order->order_total = round($cart->get_grand_total_price(), 2);
        $order->order_subtotal = round($cart->get_total_price(), 2);
        $order->order_tax = round($cart->get_order_vat(), 2);
        $order->order_shipping = round($cart->selected_shipping_rate() - $cart->selected_shipping_vat(), 2);
        $order->order_shipping_tax = round($cart->selected_shipping_vat(), 2);
        $order->order_status = 'onhold';

        $order->ship_method_id = $cart->selected_shipping_id();
        $order->order_comments = $input->get('order_comments', '', 'STRING');

        if (!$cart->need_payment()) {
            $order->payment_method = '';
        } else {
            $order->payment_method = $cart->selected_payment_method()->get_class_name();
        }

        $order->currency = Factory::getComponent('shop')->getParams()->get('currency');
        $order->currency_sign = Factory::getComponent('shop')->getParams()->get('currency_sign');
        $order->volume_unit = Factory::getComponent('shop')->getParams()->get('default_volume_unit');
        $order->weight_unit = Factory::getComponent('shop')->getParams()->get('default_weight_unit');
        $order->volume = (double) $order_dim['totalvolume'];
        $order->length = (double) $order_dim['totallength'];
        $order->width = (double) $order_dim['totalwidth'];
        $order->height = (double) $order_dim['totalheight'];
        $order->weight = (double) $order_dim['totalweight'];

        if ($cart->need_shipping()) {
            $s = json_decode($h_order->decryptShipping($order->ship_method_id));
            if (!is_object($s) || empty($s)) {
                throw new Exception(__("Shipping data is invalid!", 'com_shop'));
            }

            $carrier = Factory::getComponent('shop')->getTable('carrier')->load((int) $s->carrier_id);

            $order->shipping_name = $carrier->name;
            $order->shipping_rate_name = $s->rate_name;
        }

        $this->db->setQuery("SELECT name FROM `#_shop_methods` WHERE type='payment' AND class='" . $this->db->secure($order->payment_method) . "' LIMIT 1");
        if (!$this->db->getResource()) {
            $this->db->rollback();
            throw new Exception($this->db->getErrorString());
        }

        $order->payment_name = $this->db->loadResult();

        $ids = array();

        $billing = $customer->get_billing();

        while ($f = $billing->get_field()) {
            $order->{$f->get_name()} = $f->get_value();
        }

        $shipping = $customer->get_shipping();

        while ($f = $shipping->get_field()) {
            $order->{$f->get_name()} = $f->get_value();
        }

        $order->store();

        if (!$order->pk()) {
            return false;
        }
        $ids[] = $order->pk();

        $order_item = Factory::getComponent('shop')->getTable('order_item');
        $order_item_attribute = Factory::getComponent('shop')->getTable('order_attribute_item');

        try {

            while ($item = $cart->get_item()) {

                $order_item->reset();

                $product = Factory::getComponent('shop')->getTable('product')->load($item->id);

                if ($product->type == 'digital') {
                    $order_item->product_type = 'digital';
                } else if ($product->type == 'virtual') {
                    $order_item->product_type = 'virtual';
                }

                $manage_stocks = true;
                if ($product->manage_stock == 'no' || ($product->manage_stock == 'global' && !Factory::getComponent('shop')->getParams()->get('checkStock'))) {
                    $manage_stocks = false;
                }

                $order_item->order_id = $order->pk();
                $order_item->product_id = $item->id;
                $order_item->order_item_sku = $item->sku;
                $order_item->order_item_name = $item->name;
                $order_item->product_quantity = $item->qty;
                $order_item->product_item_price = round($item->raw_price, 2);
                $order_item->vat = round($item->vat, 2);
                $order_item->product_length = $product->product_length;
                $order_item->product_width = $product->product_width;
                $order_item->product_height = $product->product_height;
                $order_item->product_volume = $product->product_volume;
                $order_item->product_diameter = $product->product_diameter;
                $order_item->product_weight = $product->product_weight;

                if ($manage_stocks) {
                    try {
                        $order_item->stockrooms = $h_order->manage_amounts($item->id, $item->qty, 'product');
                    } catch (Exception $e) {
                        $this->db->rollback();
                        wp_delete_post($order->pk(), true);
                        return false;
                    }
                }

                $order_item->store();

                if (!$order_item->pk()) {
                    wp_delete_post($order->pk(), true);
                    $this->db->rollback();
                    return false;
                }

                if (count($item->files)) {
                    foreach ((array) $item->files as $file) {
                        $order_item_attribute->reset();
                        $order_item_attribute->order_item_id = $order_item->pk();
                        $order_item_attribute->order_id = $order->pk();
                        $order_item_attribute->section_id = $file;
                        $order_item_attribute->section = 'file';
                        if (!$product->download_limit) {
                            $order_item_attribute->downloads_remaining = null;
                        } else {
                            if ($product->download_limit_multiply_qty) {
                                $order_item_attribute->downloads_remaining = $product->download_limit * $order_item->product_quantity;
                            } else {
                                $order_item_attribute->downloads_remaining = $product->download_limit;
                            }
                        }

                        $order_item_attribute->store();

                        if (!$order_item_attribute->pk()) {

                            wp_delete_post($order->pk(), true);
                            $this->db->rollback();

                            return false;
                        }
                    }
                }

                if (count($item->props))
                    foreach ((array) $item->props as $prop) {

                        $order_item_attribute->reset();

                        $order_item_attribute->order_item_id = $order_item->pk();
                        $order_item_attribute->order_id = $order->pk();
                        $order_item_attribute->section_id = $prop->property_id;
                        $order_item_attribute->section = 'property';
                        $order_item_attribute->parent_section_id = 0;
                        $order_item_attribute->section_name = $prop->name;
                        $order_item_attribute->section_price = $prop->raw_price;
                        $order_item_attribute->section_oprand = $prop->oprand;
                        if ($manage_stocks) {
                            try {
                                $order_item_attribute->stockrooms = $h_order->manage_amounts($item->id, $item->qty, 'property');
                            } catch (Exception $e) {
                                wp_delete_post($order->pk(), true);
                                $this->db->rollback();
                                return false;
                            }
                        }

                        $order_item_attribute->store();

                        if (!$order_item_attribute->pk()) {
                            wp_delete_post($order->pk(), true);
                            $this->db->rollback();
                            return false;
                        }
                    }
            }


            //store all taxes
            $taxes = $cart->get_cart_taxes();
            foreach ((array) $taxes as $id => $tax) {
                if ($tax->get("value", 0) == 0)
                    continue;
                $order_item_attribute->reset();

                $order_item_attribute->order_item_id = null;
                $order_item_attribute->order_id = $order->pk();
                $order_item_attribute->section_id = $id;
                $order_item_attribute->section = 'tax';
                $order_item_attribute->parent_section_id = 0;
                $order_item_attribute->section_name = $tax->get("name", "Tax");
                $order_item_attribute->section_price = $tax->get("value", 0);
                $order_item_attribute->section_oprand = "+";
                $order_item_attribute->store();

                if (!$order_item_attribute->pk()) {
                    wp_delete_post($order->pk(), true);
                    $this->db->rollback();
                    return false;
                }
            }
        } catch (Exception $e) {
            wp_delete_post($order->pk(), true);
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        return $order->pk();
    }

    public function process_credit_card($gateway, $oid) {

        $order = Factory::getComponent('shop')->getTable('order')->load($oid);

        $h_order = Factory::getComponent('shop')->getHelper('order');
        $res = $gateway->do_payment($order->ID, $order->toArray());



        if (property_exists($res, 'tid') && !empty($res->tid)) {

            $order->tid = $res->tid;
        }

        $order->store();

        if (!empty($res->msg)) {
            Factory::getComponent('shop')->setMessage($res->msg);
        }
        if (!empty($res->status)) {
            $h_order->change_order_status($order->pk(), $res->status);
        }

        return true;
    }

    public function update_order_tid($id, $tid) {
        Table::getInstance("order", "shop")->load($id)
                ->set('tid', $tid)
                ->store();
    }

}
