<?php

class ordersModel extends Model {

    protected $order_stats = array('pending', 'failed', 'onhold', 'processing', 'completed', 'refunded', 'cancelled', 'shipped');
    protected $total = 0;

    public function prepare_order_item($item) {

        $product_model = model::getInstance('product_admin', 'shop');

        //attach all downloadable files to the item  
        $files = (array) $product_model->get_downloadable_files($item->product_id);
        $item->files = $files;
        $item->selected_downloads = $this->get_item_files($item->order_item_id);
        return $item;
    }

    public function list_orders() {
        $input = Factory::getApplication()->getInput();

        $start = $input->get('limitstart', 0, "INT");
        $limit = $input->get('limit', 10, "INT");

        $w = array();

        $filter_by_no = $input->get('filter', null, 'INT');
        $filter_by_status = $input->get('filter_status', null, 'WORD');
        $w[] = "1";
        if ($filter_by_no) {
            $w[] = "order_id = " . $filter_by_no;
        }
        if ($filter_by_status && in_array($filter_by_status, $this->order_stats)) {
            $w[] = "order_status = '" . $this->db->secure($filter_by_status) . "'";
        }
        $this->db->setQuery(sprintf("SELECT SQL_CALC_FOUND_ROWS * FROM #_shop_orders WHERE %s ORDER BY order_id DESC LIMIT %s,%s", implode(" AND ", $w), $start, $limit));


        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        $this->total = $this->db->found_rows();


        $rows = array();

        $order = Factory::getComponent('shop')->getTable("order");

        while ($row = $this->db->nextObject()) {

            $rows[] = clone $order->reset()->bind($row);
        }


        return $rows;
    }

    public function found_rows() {
        return (int) $this->total;
    }

    public function delete_orders($ids) {


        $ids = (array) array_map("intval", (array) $ids);


        set_time_limit(0);


        $items = array();
        $props = array();



        $this->db->setQuery("SELECT order_id,product_id,stockrooms FROM `#_shop_order_item` WHERE order_id IN(" . implode(',', $ids) . ") AND stockrooms IS NOT NULL");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        } else {
            $items = $this->db->loadObjectList('order_id');
        }

        $this->db->setQuery("SELECT order_id,stockrooms,section_id,section FROM `#_shop_order_attribute_item` WHERE order_id IN(" . implode(',', $ids) . ") AND stockrooms IS NOT NULL");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        } else {
            $props = $this->db->loadObjectList('order_id');
        }

        $done = 0;


        foreach ((array) $ids as $id) {

            $this->db->startTransaction();

            foreach ((array) $items[$id] as $item) {

                $sr = json_decode($item->stockrooms, true);

                if (empty($sr))
                    continue;

                $u_str = '';

                foreach ($sr as $k => $v) {
                    if (!$k || !$v) {
                        unset($sr[$k]);
                        continue;
                    }
                    $u_str .=" WHEN {$k} THEN stock+{$v}";
                }

                if (!empty($u_str)) {
                    $this->db->setQuery("UPDATE `#_shop_products_stockroom_xref` SET stock =
                                             CASE stockroom_id
                            {$u_str}
                                             END
                                        WHERE product_id = {$item->product_id} AND stockroom_id IN(" . implode(',', array_keys($sr)) . ")");

                    if (!$this->db->getResource()) {
                        $this->db->rollback();
                        throw new Exception($this->db->getErrorString());
                    }
                }
            }


            foreach ((array) $props[$id] as $prop) {


                $sr = json_decode($item->stockrooms, true);
                if (empty($sr))
                    continue;

                $u_str = '';
                $table = "`#_shop_property_stockroom_xref`";


                foreach ($sr as $k => $v) {
                    if (!$k || !$v) {
                        unset($sr[$k]);
                        continue;
                    }
                    $u_str .=" WHEN {$k} THEN stock+{$v}";
                }

                if (!empty($u_str)) {
                    $this->db->setQuery("UPDATE {$table} SET stock =
                                             CASE stockroom_id
                            {$u_str}
                                             END
                                        WHERE product_id = {$prop->section_id} AND stockroom_id IN(" . implode(',', array_keys($sr)) . ")");

                    if (!$this->db->getResource()) {
                        $this->db->rollback();
                        throw new Exception($this->db->getErrorString());
                    }
                }
            }

            $this->db->setQuery("DELETE FROM #_shop_order_item WHERE order_id = {$id} LIMIT 1");
            if (!$this->db->getResource()) {
                $this->db->rollback();
                throw new Exception($this->db->getErrorString());
            }
            $this->db->commit();
            $done++;
        }


        return $done;
    }

    public function load_order($id) {
        $order = array();

        $order['order'] = Factory::getComponent('shop')->getTable('order')->load($id);

        $this->db->setQuery("SELECT * FROM #_shop_order_item WHERE order_id =  " . $order['order']->pk());
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

            $order['items'][] = $o;
        }




        return $order;
    }

    public function update_user_details($id, $data) {

        $row = Factory::getComponent('shop')->getTable('order')->load($id);

        if (!empty($data['customer_phone'])) {
            $row->customer_phone = $data['customer_phone'];
        }
        if (!empty($data['customer_email'])) {
            $row->customer_email = $data['customer_email'];
        }





        foreach (array('billing', 'shipping') as $type) {

            if (!empty($data['customer_' . $type]['first_name'])) {
                $row->{'customer_' . $type}['first_name'] = $data['customer_' . $type]['first_name'];
            }
            if (!empty($data['customer_' . $type]['last_name'])) {
                $row->{'customer_' . $type}['last_name'] = $data['customer_' . $type]['last_name'];
            }
            if (!empty($data['customer_' . $type]['address'])) {
                $row->{'customer_' . $type}['address'] = $data['customer_' . $type]['address'];
            }
            if (!empty($data['customer_' . $type]['zipcode'])) {
                $row->{'customer_' . $type}['zipcode'] = $data['customer_' . $type]['zipcode'];
            }
            if (!empty($data['customer_' . $type]['city'])) {
                $row->{'customer_' . $type}['city'] = $data['customer_' . $type]['city'];
            }

            $remove_custom_state = false;
            if (!empty($data["customer_" . $type]['country_code'])) {
                $country = Factory::getComponent('shop')->getTable('country')->load($data["customer_" . $type]['country_code']);
                if ($country->pk()) {
                    $row->{"customer_" . $type}['country_code'] = $country->pk();
                    $row->{"customer_" . $type}['country_name'] = $country->country_name;
                    $row->{"customer_" . $type}['country_3_code'] = $country->country_3_code;
                    $row->{"customer_" . $type}['country_2_code'] = $country->country_2_code;

                    if (!empty($data["customer_" . $type]['state_code'])) {

                        $state = Factory::getComponent('shop')->getTable('state')->load($data["customer_" . $type]['state_code']);
                        if ($state->pk() && $state->country_id == $country->country_id) {

                            $row->{"customer_" . $type}['state_code'] = $state->pk();
                            $row->{"customer_" . $type}['state_name'] = $state->state_name;
                            $row->{"customer_" . $type}['state_3_code'] = $state->state_3_code;
                            $row->{"customer_" . $type}['state_2_code'] = $state->state_2_code;

                            $remove_custom_state = true;
                        }
                    }
                }
            }
            if (!empty($data["customer_" . $type]['custom_state'])) {
                if ($remove_custom_state)
                    $row->{"customer_" . $type}['custom_state'] = null;
                else {
                    $row->{"customer_" . $type}['custom_state'] = $data["customer_" . $type]['custom_state'];
                }
            }
        }

        $row->store();
    }

    public function item_in_order($item_id, $order_id) {
        $this->db->setQuery("SELECT count(*) FROM #_shop_order_items WHWRE order_id = " . (int) $order_id . " AND order_item_id =" . (int) $item_id);
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }
        return (int) $this->db->loadResult();
    }

    public function remove_item($item_id = null, orderTable $order = null) {

        $item_id = (int) $item_id;
        $input = Factory::getApplication()->getInput();

        if (!$item_id) {
            $item_id = $input->get('item_id', 0, "INT");
        }


        if (!$item_id) {
            return false;
        }




        $this->db->setQuery("SELECT * FROM `#_shop_order_item` WHERE order_item_id = {$item_id} ");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        } else {
            $item = $this->db->nextObject();
        }


        $this->db->setQuery("SELECT order_item_id,stockrooms,section_id,section FROM `#_shop_order_attribute_item` WHERE order_item_id = {$item_id} AND stockrooms IS NOT NULL AND section = 'property'");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        } else {
            $props = $this->db->loadObjectList();
        }





        $this->db->startTransaction();

        if ($item && !empty($item->stockrooms)) {

            $sr = json_decode($item->stockrooms, true);

            if (!empty($sr)) {

                $u_str = '';

                foreach ($sr as $k => $v) {
                    if (!$k || !$v) {
                        unset($sr[$k]);
                        continue;
                    }
                    $u_str .=" WHEN {$k} THEN stock+{$v}";
                }

                if (!empty($u_str)) {
                    $this->db->setQuery("UPDATE `#_shop_products_stockroom_xref` SET stock =
                                             CASE stockroom_id
                            {$u_str}
                                             END
                                        WHERE product_id = {$item->product_id} AND stockroom_id IN(" . implode(',', array_keys($sr)) . ")");

                    if (!$this->db->getResource()) {
                        $this->db->rollback();
                        throw new Exception($this->db->getErrorString());
                    }
                }
            }
        }


        foreach ((array) $props as $prop) {


            $sr = json_decode($item->stockrooms, true);
            if (empty($sr))
                continue;

            $u_str = '';
            $table = "`#_shop_property_stockroom_xref`";

            foreach ($sr as $k => $v) {
                if (!$k || !$v) {
                    unset($sr[$k]);
                    continue;
                }
                $u_str .=" WHEN {$k} THEN stock+{$v}";
            }

            if (!empty($u_str)) {
                $this->db->setQuery("UPDATE {$table} SET stock =
                                             CASE stockroom_id
                        {$u_str}
                                             END
                                        WHERE product_id = {$prop->section_id} AND stockroom_id IN(" . implode(',', array_keys($sr)) . ")");

                if (!$this->db->getResource()) {
                    $this->db->rollback();
                    throw new Exception($this->db->getErrorString());
                }
            }
        }

        if (!is_object($order)) {
            $order = Factory::getComponent('shop')->getTable('order')->load($item->order_id);
        }
        $order->order_tax -= $item->vat * $item->product_quantity;
        $order->order_subtotal -= $item->product_item_price * $item->product_quantity;
        $order->order_total -= ($item->product_item_price + $item->vat) * $item->product_quantity;

        if (!is_object($order)) {
            $order->store();
        }

        $this->db->setQuery("DELETE FROM #_shop_order_item WHERE order_item_id = {$item_id} LIMIT 1");
        if (!$this->db->getResource()) {
            $this->db->rollback();
            throw new Exception($this->db->getErrorString());
        }
        $this->db->commit();
        if (!is_object($order)) {
            return true;
        }
        return $order;
    }

    public function get_item_files($id) {

        $this->db->setQuery("SELECT * FROM `#_shop_order_attribute_item` WHERE order_item_id =" . (int) $id . " AND section ='file'");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }
        $rows = array();

        while ($o = $this->db->nextObject()) {
            $rows[$o->section_id] = new stdClass();
            $rows[$o->section_id]->downloads_remaining = $o->downloads_remaining;
            $rows[$o->section_id]->expires = $o->expires;
        }

        return (array) $rows;
    }

    public function update_item_files(order_itemTable $item) {

        if (!$item->pk())
            return false;


        $post = Factory::getApplication()->getInput()->post;


        if (isset($post['access_granted'][$item->order_item_id]) && $item->access_granted != $post['access_granted'][$item->order_item_id]) {
            $item->access_granted = $post['access_granted'][$item->order_item_id];
        }


        if (isset($post['files'][$item->order_item_id])) {

            foreach ((array) $post['files'][$item->order_item_id] as $k => $file) {
                if (!isset($file['id']))
                    unset($post['files'][$item->order_item_id][$k]);
            }

            $ids = array_map('intval', array_keys($post['files'][$item->order_item_id]));


            $w = array();
            if (count($ids)) {
                $w[] = " section_id NOT IN(" . implode(',', $ids) . ") ";
            }
            $w[] = " section = 'file' ";
            $w[] = " order_item_id = " . $item->order_item_id;

            $this->db->setQuery("DELETE FROM #_shop_order_attribute_item WHERE " . implode("AND", $w));

            if (!$this->db->getResource()) {

                return false;
            }

            $values = array();

            foreach ($post['files'][$item->order_item_id] as $k => $file) {


                $expires = 'NULL';
                if (!empty($file['expires'])) {

                    $expires = (int) strtotime($file['expires']);
                }
                if (trim($file['downloads_remaining']) == '') {
                    $downloads_remaining = 'NULL';
                } else {
                    $downloads_remaining = (int) $file['downloads_remaining'];
                }

                $values[] = sprintf("(NULL,%s,%s,%s,'file',%s,FROM_UNIXTIME(%s))", (int) $item->order_item_id, (int) $item->order_id, (int) $file['id'], $downloads_remaining, $expires);
            }
            $this->db->setQuery("REPLACE INTO #_shop_order_attribute_item (order_att_item_id,order_item_id,
                                order_id,section_id,section,downloads_remaining,expires) VALUES" . implode(",", $values));
            if (!$this->db->getResource()) {

                return false;
            }
        }
        return true;
    }

    public function update_price(orderTable $order, array $ids) {

        $input = Factory::getApplication()->getInput();

        $prices = $input->get('new_price', array(), 'ARRAY');
        $qtys = $input->get('new_quantity', array(), 'ARRAY');
        $tax = Factory::getComponent('shop')->getHelper('tax');
        $vat = 0;
        $db = Factory::getDBO();


        $country = $state = null;
        switch (Factory::getComponent('shop')->getParams()->get('vatType')) {

            case '3':
                $country = $order->get('billing_country');
                $state = $order->get('billing_state');
                break;

            case '1':
                break;
            case '0':

            case '2':
            default:

                $country = $order->get('shipping_country');
                $state = $order->get('shipping_state');

                break;
        }




        foreach ((array) $ids as $id) {

            $item = Factory::getComponent('shop')->getTable('order_item')->load((int) $id);



            if (!$item->pk())
                continue;

            $price = (double) $prices[$id];
            $qty = (int) $qtys[$id];

            $product = Factory::getComponent('shop')->getTable('product')->load($item->product_id);

            if ($product->pk()) {

                if (( Factory::getComponent('shop')->getParams()->get('vat') && $product->vat == 'global' ) || $product->vat == 'yes') {

                    if ($product->tax_group_id) {
                        $tax_rate = $tax->get_tax_rate($country, $state, $product->tax_group_id);
                        $price += $price * $tax_rate;
                    } else {
                        $tax_rate = $tax->get_tax_rate($country, $state);
                        $vat = $price * $tax_rate;
                    }
                }
            } else {
                if (Factory::getComponent('shop')->getParams()->get('vat')) {
                    $tax_rate = $tax->get_tax_rate($country, $state);
                    $vat = $price * $tax_rate;
                }
            }


            $tmp_tax = $order->order_tax;
            $tmp_subtotal = $order->order_subtotal;
            $tmp_total = $order->order_total;

            $order->order_tax -= abs($item->vat * $item->product_quantity);
            $order->order_subtotal -= abs($item->product_item_price * $item->product_quantity);

            $order->order_total -= abs(($item->product_item_price + $item->vat) * $item->product_quantity);





            if ($order->order_tax < 0)
                $order->order_tax = 0;
            if ($order->order_subtotal < 0)
                $order->order_subtotal = 0;
            if ($order->order_total < 0)
                $order->order_total = 0;
            if ($qty < 0)
                $qty = 0;

            $item->product_item_price = abs($price);
            $item->vat = abs($vat);
            $item->product_quantity = $qty;

            $order->order_tax += abs($item->vat * $item->product_quantity);
            $order->order_subtotal += abs($item->product_item_price * $item->product_quantity);
            $order->order_total += abs(($item->product_item_price + $item->vat) * $item->product_quantity);

            $this->update_item_files($item);
            try {



                $item->store();
            } catch (Exception $e) {
                $order->order_tax = $tmp_tax;
                $order->order_subtotal = $tmp_subtotal;
                $order->order_total = $tmp_total;
            }
        }
        return true;
    }

    public function update() {


        $input = Factory::getApplication()->getInput();


        $wp_post = null;
        $wp_post = get_post($input->get('id', 0, "INT"));
        if (!$wp_post)
            return;


        $helper = Factory::getComponent('shop')->getHelper('order');

        $status = $input->get('new_status', null, "INT");

        if (term_exists($status, 'order_status')) {

            Factory::getComponent('shop')->getHelper('order')->change_order_status($wp_post->ID, $status);
        }

        $order = Factory::getComponent('shop')->getTable('order')->load($wp_post->ID)->bind($input->post);


        $sid = $input->get('new_shipping_rate', null, "STRING");

        $rates = $this->get_shipping_rates($order->pk());

        $rate = null;
        foreach ((array) $rates as $r) {

            if ($r->id == $sid) {
                $rate = $r;
                break;
            }
        }



        $s = json_decode($helper->decryptShipping($rate->id));
        if ($order->shipping_method_id != $rate->id && !empty($rate) && is_object($s) && !empty($s)) {

            $order->order_total -= ( $order->order_shipping + $order->order_shipping_tax );
            $order->order_total += $rate->rate;
            $order->order_shipping = $rate->raw_rate;
            $order->order_shipping_tax = $rate->rate - $rate->raw_rate;
            $order->ship_method_id = $rate->id;

            $helper = Factory::getComponent('shop')->getHelper('order');

            $carrier = Factory::getComponent('shop')->getTable('carrier')->load((int) $s->carrier_id);

            $order->shipping_name = $carrier->name;
            $order->shipping_rate_name = $s->rate_name;
        }

        $items = array_map("intval", $input->get("items", array(), "ARRAY"));
        $this->db->setQuery("SELECT order_item_id FROM #_shop_order_item WHERE order_id =" . (int) $wp_post->ID);
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        $ids = array_diff((array) $this->db->loadArray(), $items);

        foreach ((array) $ids as $id) {
            $this->remove_item($id, $order);
        }
        unset($ids);

        $this->update_price($order, $items);

//update shipping and billing fields and add all new
//added trought the fields api 

        $billing = $helper->get_order_billing($order->pk());
        $shipping = $helper->get_order_shipping($order->pk());

        while ($f = $billing->get_field()) {

            if (isset($input->post[$f->get_name()])) {
                $f->set_value(trim($input->post[$f->get_name()]));
            }
            $order->{$f->get_name()} = $f->get_value();
        }

        while ($f = $shipping->get_field()) {

            if (isset($input->post[$f->get_name()])) {
                $f->set_value(trim($input->post[$f->get_name()]));
            }
            $order->{$f->get_name()} = $f->get_value();
        }




        $order->store();

        $order_helper = Helper::getInstance("order", "shop");
        $taxes = $order_helper->recalc_order_taxes($order->ID);

        //clean replace old taxes with recalculated 
        $db = Factory::getDBO();
        $db->setQuery("DELETE FROM #_shop_order_attribute_item WHERE section = 'tax' AND order_id = " . (int) $order->ID);



        if (!empty($taxes)) {
            $order_item_attribute = Table::getInstance("order_attribute_item", "shop");

            foreach ((array) $taxes as $id => $tax) {
                if ($tax->get("value", 0) == 0)
                    continue;
                $order_item_attribute->reset();

                $order_item_attribute->order_item_id = NULL;
                $order_item_attribute->order_id = $order->ID;
                $order_item_attribute->section_id = $id;
                $order_item_attribute->section = 'tax';
                $order_item_attribute->parent_section_id = 0;
                $order_item_attribute->section_name = $tax->get("name", "Tax");
                $order_item_attribute->section_price = $tax->get("value", 0);
                $order_item_attribute->section_oprand = "+";
                $order_item_attribute->store();
            }
        }


        if ($input->get('send_invoice', 0, "INT") == 1) {
            do_action('orillacart_send_order_invoice', $order->pk());
        }
    }

    public function get_shipping_rates($id) {

        $row = Factory::getComponent('shop')->getTable('order')->load($id);
        if (!$row->pk())
            return array();

//add the order object to all shipping methods, so they know that they have to list
//rates using the data in the order and not trying to load data from current user or the cart!
        standart_shipping::in_order($row);

//get available rates from all carriers.

        return (array) Factory::getComponent('shop')->getHelper('cart')->get_shipping_rates();
    }

    public function filter_post_by_title($where, &$wp_query) {


        global $wpdb;
        if ($post_title_like = $wp_query->get('filter_product_title')) {

            $where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'' . esc_sql(like_escape($post_title_like)) . '%\'';
        }
        return $where;
    }

    public function get_parent_list($str, $exclude = array()) {



        add_filter('posts_where', array($this, 'filter_post_by_title'), 10, 2);

        $res = array();
        $this->query = new WP_Query();
        $this->query->query(array('posts_per_page' => 10,
            'post_parent' => 0,
            'post_status' => 'publish',
            'post_type' => 'product',
            'post__not_in' => $exclude,
            'filter_product_title' => $str));

        while ($this->query->have_posts()) {
            $this->query->the_post();
            $res[get_the_ID()] = get_the_title();
        }

        wp_reset_postdata();
        return $res;
    }

    public function get_users_by_str($str) {

        $res = array();

        $this->db->setQuery("SELECT ID,user_nicename as nicename FROM #_users WHERE user_nicename LIKE '" . $this->db->secure($str) . "%'");
        $rows = $this->db->loadObjectList();
        foreach ((array) $rows as $row) {
            $res[$row->ID] = $row->nicename;
        }


        return $res;
    }

    public function save_product_to_order($oid, $pid, $properties = array(), $qty = 1) {


        if (!$qty || $qty < 1) {
            $qty = 1;
        }
        $product_admin = model::getInstance('product_admin', 'shop');
        $order_helper = helper::getInstance('order', 'shop');
        $product_helper = helper::getInstance('product_helper', 'shop');
        $app = factory::getComponent('shop');
//include frontend model to use stock check method
        Model::addIncludePath('shop', dirname($app->getComponentPath()) . DS . "front" . DS . "models");

        $product = model::getInstance('product', 'shop');

        $this->db->startTransaction();

        $variation = null;

//we will add variation
        if ($variation = $product_admin->variation_exists($pid, $properties)) {

            $properties = array();
        } else {
            $variation = $pid;
        }

        $stock = 0;

        if ($stock = $product->is_variation_available($variation, $properties)) {

            if ($stock < $qty) {
                throw new Exception(__("Not enough items in stock.", "com_shop"));
            }

//check is passed we have the needed stock so we can proceed
//we take the product data, so that we can alter the dimentions of the order
            $product_row = table::getInstance("product", 'shop')->load($variation);
//and we also take the order row, so that we can update it 

            $order = table::getInstance("order", 'shop')->load($oid);

            $country = $state = null;
            switch (Factory::getComponent('shop')->getParams()->get('vatType')) {

                case '3':
                    $country = $order->get('billing_country');
                    $state = $order->get('billing_state');
                    break;

                case '1':
                    break;
                case '0':

                case '2':
                default:

                    $country = $order->get('shipping_country');
                    $state = $order->get('shipping_state');

                    break;
            }





            $price = $product_helper->get_price_with_tax($product_row, $properties, null, $country, $state);

            $order->order_total += $qty * $price->price;
            $order->order_subtotal += $qty * $price->raw_price;
            $order->order_tax += $qty * ($order->order_subtotal * ($price->tax / 100));

            $order->currency = Factory::getComponent('shop')->getParams()->get('currency');
            $order->currency_sign = Factory::getComponent('shop')->getParams()->get('currency_sign');
            $order->volume_unit = Factory::getComponent('shop')->getParams()->get('default_volume_unit');
            $order->weight_unit = Factory::getComponent('shop')->getParams()->get('default_weight_unit');

            $order->volume += (double) $qty * $product_row->get('product_volume', 0);
            $order->length += (double) $qty * $product_row->get('product_length', 0);
            $order->width += (double) $qty * $product_row->get('product_width', 0);
            $order->height +=(double) $qty * $product_row->get('product_height', 0);
            $order->weight += (double) $qty * $product_row->get('product_weight', 0);


//store the item
            $order_item = $app->getTable('order_item');
            $order_item->reset();




            $manage_stocks = true;
            if ($product_row->manage_stock == 'no' || ($product_row->manage_stock == 'global' && !Factory::getComponent('shop')->getParams()->get('checkStock'))) {
                $manage_stocks = false;
            }

            $order_item->order_id = $order->pk();
            $order_item->product_id = $product_row->id;
            $order_item->order_item_sku = $product_row->sku;
            $order_item->order_item_name = $product_row->name;
            $order_item->product_quantity = $qty;
            $order_item->product_item_price = round($price->raw_price, 2);
            $order_item->vat = round($order_item->product_item_price * ($price->tax / 100), 2);
            $order_item->product_length = $product_row->product_length;
            $order_item->product_width = $product_row->product_width;
            $order_item->product_height = $product_row->product_height;
            $order_item->product_volume = $product_row->product_volume;
            $order_item->product_diameter = $product_row->product_diameter;
            $order_item->product_weight = $product_row->product_weight;

            if ($product_row->type == 'digital') {
                $order_item->product_type = 'digital';
            } else if ($product_row->type == 'virtual') {
                $order_item->product_type = 'virtual';
            }

            try {
                if ($manage_stocks) {
                    $order_item->stockrooms = $order_helper->manage_amounts($variation, $qty);
                }

                $order->store();

                $order_item->store();

//here we will store the properties if the product is not variation and there are any
                $order_item_attribute = Factory::getComponent('shop')->getTable('order_attribute_item');

                if (!empty($properties)) {

                    $this->db->setQuery("SELECT * FROM #_shop_attribute_property WHERE property_id IN(" . implode(',', $properties) . ")");

                    while ($prop = $this->db->nextObject()) {


                        $order_item_attribute->reset();
                        $order_item_attribute->order_item_id = $order_item->pk();
                        $order_item_attribute->order_id = $order->pk();
                        $order_item_attribute->section_id = $prop->property_id;
                        $order_item_attribute->section = 'property';
                        $order_item_attribute->parent_section_id = 0;
                        $order_item_attribute->section_name = $prop->property_name;
                        $order_item_attribute->section_price = $prop->property_price;
                        $order_item_attribute->section_oprand = $prop->oprand;
                        if ($manage_stocks) {
                            $order_item_attribute->stockrooms = $order_helper->manage_amounts($prop->property_id, $qty, 'property');
                        }

                        $order_item_attribute->store();
                    }
                }
            } catch (Exception $e) {

                $this->db->rollback();

                throw $e;
            }
        } else {

            if ($stock < $qty) {
                throw new Exception(__("Not enough items in stock.", "com_shop"));
            }
        }

        $this->db->commit();
    }

}
