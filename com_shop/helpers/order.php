<?php

class order {

    public function change_order_status($id, $status = 'pending') {

        if (!term_exists($status, 'order_status'))
            return false;

        $order = Factory::getComponent('shop')->getTable('order')->load($id);
        if (!$order->pk())
            return false;

        $ok = true;
        if ($order->payment_method && !has_term($status, 'order_status', (int) $id)) {

            $methods = Factory::getComponent('shop')->getHelper('cart')->get_payment_methods();
            foreach ((array) $methods as $method) {
                if ($method instanceof $order->payment_method && method_exists($method, 'change_order_state')) {

                    $ok = $method->change_order_state($status, $id, $order->toArray());
                }
            }
        }

        if ($ok) {
            $old = wp_get_object_terms((int) $id, 'order_status', array("fields" => "slugs"));

            $old = isset($old[0]) ? $old[0] : null;

            if (is_numeric($status)) {
                $term = get_term((int) $status, "order_status");
                $status = $term->slug;
            }

            wp_set_object_terms((int) $id, $status, 'order_status');

            if ($status == 'completed') {
                $db = Factory::getDBO();

                $db->setQuery("SELECT * FROM #_shop_order_item WHERE order_id = " . $order->pk() . " AND product_type = 'digital'");
                if (!$db->getResource()) {
                    throw new Exception($db->getErrorString());
                }

                $p = Factory::getComponent('shop')->getTable('product');
                $oi = Factory::getComponent('shop')->getTable('order_item');
                $items = $db->loadObjectList();

                foreach ($items as $item) {

                    if ($item->access_granted != '0000-00-00 00:00:00' && !empty($item->access_granted))
                        continue;

                    $p->reset();
                    $oi->reset();
                    $p->load($item->product_id);
                    $oi->load((int) $item->order_item_id);

                    $oi->access_granted = current_time("mysql");


                    $expiry = (empty($p->download_expiry)) ? null : (int) $p->download_expiry;

                    if ($expiry)
                        $expiry = date("Y-m-d", strtotime('NOW + ' . $expiry . ' DAY'));

                    $db->setQuery("UPDATE #_shop_order_attribute_item SET expires = '" . $expiry . "' WHERE section='file' AND order_item_id = " . $item->order_item_id);

                    $oi->store();
                }
            }

            if ($old != $status)
                do_action('orillacart_order_status_change_email', $old, $status, $id);

            return true;
        } else {

            return false;
        }
    }

    public function manage_amounts($oid, $qty = 1, $type = 'product') {

        $db = Factory::getDBO();

        $table = '#_shop_products_stockroom_xref';
        $o_name = 'product_id';


        switch ($type) {

            case "property":
                $table = '#_shop_property_stockroom_xref';
                $o_name = 'property_id';
                break;
        }



        $sql = "SELECT x.*, if(s.delivery_time = 'week',s.max_del_time * 7, s.max_del_time) delivery_order  FROM #_shop_stockroom as s
			INNER JOIN " . $table . " as x ON x.stockroom_id = s.id
			WHERE x." . $o_name . " = " . (int) $oid . " AND s.published = 'yes'
			ORDER BY delivery_order ASC, x.stock ";



        $db->setQuery($sql);
        if (!$db->getResource()) {
            throw new Exception($db->getErrorString());
        }



        $used = array();

        $needed = $qty;


        while ($o = $db->nextObject()) {


            if ($o->stock >= $needed) {
                $used[$o->stockroom_id] = $needed;
                $needed = 0;
                break;
            } else {
                $used[$o->stockroom_id] = $o->stock;
                $needed -= $o->stock;
            }
        }
        $db->reset();

        foreach ((array) $used as $k => $v) {

            $db->setQuery("UPDATE " . $table . " SET stock = stock - " . (int) $v . "
                                   WHERE " . $o_name . " = " . $oid . " AND stockroom_id = " . (int) $k . " LIMIT 1");

            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }
        }

        return $used;
    }

    public function order_has_files($id) {

        $db = Factory::getDBO();

        $db->setQuery("SELECT count(*) FROM #_shop_order_item WHERE product_type='digital' AND order_id = " . (int) $id);
        return (int) $db->loadResult();
    }

    public function get_order($id) {

        $row = Factory::getComponent('shop')->getTable('order')->load($id);

        if (!$row->pk())
            return false;

        return ArrayHelper::fromObject($row);
    }

    public function format_shipping($id) {
        $data = $this->get_order($id);
        $helper = Factory::getComponent('shop')->getHelper('customer');

        return $helper->format_shipping($data);
    }

    public function get_order_shipping($id = null) {

        $fields = Factory::getComponent('shop')->getHelper('customer')->get_shipping_fields();


        if (!$id)
            return $fields;
        $data = $this->get_order($id);
        $country = null;


        while ($f = $fields->get_field()) {
            if (isset($data[$f->get_name()])) {
                $f->set_value($data[$f->get_name()]);
                if ($f instanceof country)
                    $country = $f->get_value();
                if ($f instanceof state && $country)
                    $f->set_country($country);
            }
        }

        return $fields;
    }

    public function get_order_billing($id = null) {

        $fields = Factory::getComponent('shop')->getHelper('customer')->get_billing_fields();

        if (!$id)
            return $fields;

        $data = $this->get_order($id);

        $country = null;

        while ($f = $fields->get_field()) {
            if (isset($data[$f->get_name()])) {
                $f->set_value($data[$f->get_name()]);
                if ($f instanceof country)
                    $country = $f->get_value();
                if ($f instanceof state && $country)
                    $f->set_country($country);
            }
        }

        return $fields;
    }

    public function format_billing($id) {
        $data = $this->get_order($id);
        $helper = Factory::getComponent('shop')->getHelper('customer');

        return $helper->format_billing($data);
    }

    public function encryptShipping($Str_Message, $replace = true) {
        $Len_Str_Message = strlen($Str_Message);
        $Str_Encrypted_Message = "";
        for ($Position = 0; $Position < $Len_Str_Message; $Position++) {
            $Key_To_Use = (($Len_Str_Message + $Position) + 1);

            $Key_To_Use = (255 + $Key_To_Use) % 255;
            $Byte_To_Be_Encrypted = SUBSTR($Str_Message, $Position, 1);
            $Ascii_Num_Byte_To_Encrypt = ORD($Byte_To_Be_Encrypted);
            $Xored_Byte = $Ascii_Num_Byte_To_Encrypt ^ $Key_To_Use;  //xor operation
            $Encrypted_Byte = CHR($Xored_Byte);
            $Str_Encrypted_Message .= $Encrypted_Byte;
        }
        $result = base64_encode($Str_Encrypted_Message);
        if ($replace) {
            //$result = str_replace("+", " ", $result);
            $result = urlencode($result);
        }
        return $result;
    }

    public function decryptShipping($Str_Message, $replace = true) {

        if ($replace) {
            //   $Str_Message = str_replace(" ", "+", $Str_Message);
            $Str_Message = urldecode($Str_Message);
        }
        $Str_Message = base64_decode($Str_Message);
        $Len_Str_Message = strlen($Str_Message);
        $Str_Encrypted_Message = "";
        for ($Position = 0; $Position < $Len_Str_Message; $Position++) {
            $Key_To_Use = (($Len_Str_Message + $Position) + 1);

            $Key_To_Use = (255 + $Key_To_Use) % 255;
            $Byte_To_Be_Encrypted = SUBSTR($Str_Message, $Position, 1);
            $Ascii_Num_Byte_To_Encrypt = ORD($Byte_To_Be_Encrypted);
            $Xored_Byte = $Ascii_Num_Byte_To_Encrypt ^ $Key_To_Use;  //xor operation
            $Encrypted_Byte = CHR($Xored_Byte);
            $Str_Encrypted_Message .= $Encrypted_Byte;
        }
        return $Str_Encrypted_Message;
    }

    public function getUnitConversation($globalUnit, $calcUnit) {
        /*
         * calculation for setting unit value
         */
        $unit = 1;
        switch ($calcUnit) {

            case "mm":

                switch ($globalUnit) {
                    case "mm":
                        $unit = 1;
                        break;

                    case "cm":
                        $unit = 0.1;
                        break;

                    case "m":
                        $unit = 0.001;
                        break;

                    case "inch":
                        $unit = 0.0393700787;
                        break;
                    case "feet":
                        $unit = 0.0032808399;
                        break;
                }

                break;

            case "cm":

                switch ($globalUnit) {
                    case "mm":
                        $unit = 10;
                        break;

                    case "cm":
                        $unit = 1;
                        break;

                    case "m":
                        $unit = 0.01;
                        break;

                    case "inch":
                        $unit = 0.393700787;
                        break;
                    case "feet":
                        $unit = 0.032808399;
                        break;
                }

                break;

            case "m":

                switch ($globalUnit) {
                    case "mm":
                        $unit = 1000;
                        break;

                    case "cm":
                        $unit = 100;
                        break;

                    case "m":
                        $unit = 1;
                        break;

                    case "inch":
                        $unit = 39.3700787;
                        break;
                    case "feet":
                        $unit = 3.2808399;
                        break;
                }

                break;

            case "inch":

                switch ($globalUnit) {
                    case "mm":
                        $unit = 25.4;
                        break;

                    case "cm":
                        $unit = 2.54;
                        break;

                    case "m":
                        $unit = 0.0254;
                        break;

                    case "inch":
                        $unit = 1;
                        break;
                    case "feet":
                        $unit = 0.0833333333;
                        break;
                }

                break;

            case "feet":

                switch ($globalUnit) {
                    case "mm":
                        $unit = 304.8;
                        break;

                    case "cm":
                        $unit = 30.48;
                        break;

                    case "m":
                        $unit = 0.3048;
                        break;

                    case "inch":
                        $unit = 12;
                        break;
                    case "feet":
                        $unit = 1;
                        break;
                }

                break;

            case "kg":

                switch ($globalUnit) {
                    case "pounds":
                    case "lbs":
                        $unit = 2.20462262;
                        break;

                    case "gram":
                        $unit = 1000;
                        break;

                    case "kg":
                        $unit = 1;
                        break;
                }

                break;

            case "pounds":
            case "lbs":

                switch ($globalUnit) {
                    case "pounds":
                    case "lbs":
                        $unit = 1;
                        break;

                    case "gram":
                        $unit = 453.59237;
                        break;

                    case "kg":
                        $unit = 0.45359237;
                        break;
                }

                break;

            case "gram":

                switch ($globalUnit) {
                    case "pounds":
                    case "lbs":
                        $unit = 0.00220462262;
                        break;

                    case "gram":
                        $unit = 1;
                        break;

                    case "kg":
                        $unit = 0.001;
                        break;
                }

                break;
        }
        // End

        return $unit;
    }

    public function get_order_taxes($id) {

        if (!is_numeric($id)) {
            return array();
        }

        $db = Factory::getDBO();

        $db->setQuery("SELECT section_name as name,section_price as value FROM #_shop_order_attribute_item where section='tax' and order_id = " . (int) $id);

        return $db->loadObjectList();
    }

    public function recalc_order_taxes($oid, $flush = false) {

        if (!is_numeric($oid) || empty($oid))
            return array();
        //prepare the order as reistry object for easier manipulation.
        $order = new Registry($this->get_order($oid));


        $country = $state = null;
        $params = Factory::getComponent('shop')->getParams();
        switch ($params->get('vatType')) {
            case '0':
            case '2':
                $country = $order->get('shipping_country');
                $state = $order->get('shipping_state');
                break;

            case '3':
                $country = $order->get('billing_country');
                $state = $order->get('billing_state');
                break;

            case '1':
            default:

                $country = $params->get('shop_country');
                $state = $params->get('shop_state');
                break;
        }
        if (empty($country))
            return array();
        $db = Factory::getDBO();

        $db->setQuery("SELECT product_id as pid ,product_quantity as qty FROM #_shop_order_item WHERE order_id = " . (int) $oid);

        $rows = $db->loadObjectList();

        static $cache = array();
        if ($flush && !empty($cache))
            $cache = array();

        if (count($cache[$oid])) {
            return $cache[$oid];
        }

        foreach ((array) $rows as $row) {

            $pid = (int) $row->pid;
            $qty = (int) $row->qty;
            $product = Factory::getComponent('shop')->getTable('product')->load($pid);

            $props = array();
            $files = array();

            $db->setQuery("SELECT section_id FROM #_shop_order_attribute_item WHERE section = 'property' AND order_item_id =" . (int) $pid . " AND order_id =" . (int) $oid);
            $props = (array) array_map("intval", (array) $db->loadArray());

            $db->setQuery("SELECT section_id FROM #_shop_order_attribute_item WHERE section = 'file' AND order_item_id =" . (int) $pid . " AND order_id =" . (int) $oid);
            $files = (array) array_map("intval", (array) $db->loadArray());



            $rates = array();


            if (( Factory::getComponent('shop')->getParams()->get('vat') && $product->vat == 'global' ) || $product->vat == 'yes') {



                $validate = Helper::getInstance('validation', 'shop');
                if (!$validate->country_has_states($country) || !$validate->state_in_country($state, $country)) {
                    $state = null;
                }


                if ($product->tax_group_id) {

                    $rates = Factory::getComponent('shop')->getHelper('tax')->get_matched_rates($country, $state, $product->tax_group_id);
                } else {

                    $rates = Factory::getComponent('shop')->getHelper('tax')->get_matched_rates($country, $state);
                }

                if (count($rates)) {

                    $price = Factory::getComponent('shop')->getHelper('product_helper')->get_price($pid, $props, $files)->raw;

                    $price = $price * $qty;


                    foreach ((array) $rates as $id => $rate) {

                        if (isset($cache[$oid]) && array_key_exists($id, $cache[$oid])) {
                            $cache[$oid][$id]->value += $price * $rate->rate;
                        } else {
                            if (!isset($cache[$oid])) {
                                $cache[$oid] = array();
                            }
                            $cache[$oid][$id] = clone $rate;
                            $cache[$oid][$id]->set("value", $price * $rate->rate);
                        }
                    }
                }
            }
        }

        return $cache[$oid];
    }

}
