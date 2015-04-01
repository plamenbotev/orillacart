<?php

final class cart {

    protected $shipping_id = null;
    protected $shipping_rate = 0;
    protected $payment_id = null;
    protected $payment = null;
    protected $total_price = 0;
    protected $order_vat = 0;
    protected $shipping_vat = 0;

    public static function getInstance() {

        static $instance = null;

        if (!($instance instanceof self)) {
            $instance = new self();
        }

        return $instance;
    }

    
    protected function __construct() {

        if (!session_id()) {
            @session_start();
        }
        if (!isset($_SESSION['cart']))
            $_SESSION['cart'] = array();
    }

    function getProductVolumeShipping() {

        $length = array();
        $width = array();
        $height = array();
        $length_q = array();
        $width_q = array();
        $height_q = array();
        $Lmax = 0;
        $Ltotal = 0;
        $Wmax = 0;
        $Wtotal = 0;
        $Hmax = 0;
        $Htotal = 0;

        // cart loop
        while ($p = $this->get_item()) {



            $data = Factory::getComponent('shop')->getTable('product')->load($p->id);

            if (!has_term('regular', 'product_type', $p->id))
                continue;

            $length[$i] = $data->product_length;
            $width[$i] = $data->product_width;
            $height[$i] = $data->product_height;

            $tmparr = array($length[$i], $width[$i], $height[$i]);
            $switch = array_search(min($tmparr), $tmparr);
            switch ($switch) {
                case 0:
                    $length_q[$i] = $data->product_length * $p->qty;
                    $width_q[$i] = $data->product_width;
                    $height_q[$i] = $data->product_height;
                    break;
                case 1:
                    $length_q[$i] = $data->product_length;
                    $width_q[$i] = $data->product_width * $p->qty;
                    $height_q[$i] = $data->product_height;
                    break;
                case 2:
                    $length_q[$i] = $data->product_length;
                    $width_q[$i] = $data->product_width;
                    $height_q[$i] = $data->product_height * $p->qty;
                    break;
            }
        }

        // get maximum length
        if (count($length) > 0)
            $Lmax = max($length);
        // get total length
        if (count($length_q) > 0)
            $Ltotal = array_sum($length_q);
        // get maximum width
        if (count($width) > 0)
            $Wmax = max($width);
        // get total width
        if (count($width_q) > 0)
            $Wtotal = array_sum($width_q);
        // get maximum height
        if (count($height) > 0)
            $Hmax = max($height);
        // get total height
        if (count($height_q) > 0)
            $Htotal = array_sum($height_q);

        // 3 cases are available for shipping boxes
        $cases = array();
        $cases[0]['length'] = $Lmax;
        $cases[0]['width'] = $Wmax;
        $cases[0]['height'] = $Htotal;

        $cases[1]['length'] = $Lmax;
        $cases[1]['width'] = $Wtotal;
        $cases[1]['height'] = $Hmax;

        $cases[2]['length'] = $Ltotal;
        $cases[2]['width'] = $Wmax;
        $cases[2]['height'] = $Hmax;

        return $cases;
    }

    public function getCartItemDimention() {

        $totalQnt = 0;
        $totalWeight = 0;
        $totalVolume = 0;
        $totalLength = 0;
        $totalheight = 0;
        $totalwidth = 0;

        foreach ((array) $_SESSION['cart'] as $o) {
            $p = Factory::getComponent('shop')->getTable('product')->load($o->id);

            //we need data only from phisical products, that will be shiped
            if ($p->type != 'regular') {
                continue;
            }
            $totalQnt += $o->qty;
            $totalWeight += $p->product_weight * $o->qty;
            $totalVolume += $p->product_volume * $o->qty;
            $totalLength += $p->product_length * $o->qty;
            $totalheight += $p->product_height * $o->qty;
            $totalwidth += $p->product_width * $o->qty;
        }

        $ret = array(
            "totalquantity" => $totalQnt,
            "totalweight" => $totalWeight,
            "totalvolume" => $totalVolume,
            "totallength" => $totalLength,
            "totalheight" => $totalheight,
            "totalwidth" => $totalwidth
        );
        return $ret;
    }

    public function empty_cart() {

        $db = Factory::getDBO();
        $db->startTransaction();
        $db->setQuery("DELETE FROM #_shop_cart WHERE session_id = '" . $db->secure(session_id()) . "'");

        if (!$db->getResource()) {
            $db->rollback();
            throw new Exception($db->getErrorString());
        }

        $db->commit();
        $_SESSION['cart'] = array();
        $this->total_price = 0;
        return true;
    }

    public function add_to_cart($pid, $qty = 1, $props = array(), $files = array()) {

        $app = null;
        $this->total_price = 0;
        if ($qty < 1) {
            $qty = 1;
        }
        $app = Factory::getComponent('shop');
        $product = Table::getInstance('product', 'shop')->load($pid);
        $product_helper = $app->getHelper('product_helper');
        $model = Model::getInstance('product', 'shop');

        if (!$model->is_product($pid) || get_post_meta((int) $pid, '_not_for_sale', true) == 'yes') {
            return false;
        }

        $props = (array) array_map('intval', $props);


        if ($files === null) {
            $files = (array) $model->get_downloadable_files((int) $pid);
            $files = (array) array_keys($files);
        } else {
            $files = (array) array_map('intval', $files);
        }
        $db = Factory::getDBO();



        $db->setQuery("SELECT att.attribute_id
                        FROM #_shop_attribute AS att
                        LEFT JOIN #_shop_product_attribsets AS aset ON aset.sid = att.attribute_set_id
                        LEFT JOIN #_shop_attribute_set AS the_set ON the_set.attribute_set_id = aset.sid AND the_set.published = 'yes'
                        WHERE ( att.product_id =" . ($product->post->post_parent ? (int) $product->post->post_parent : (int) $pid) . " || aset.pid =" . ($product->post->post_parent ? (int) $product->post->post_parent : (int) $pid) . " ) AND att.attribute_required = 'yes'");

        if (!$db->getResource()) {
            throw new Exception($db->getErrorString());
        }

        $req_atts = $db->loadArray();

        if (!empty($req_atts) && empty($props)) {
            Factory::getComponent('shop')->addError(__("Select required attributes!", 'com_shop'));
            return false;
        }

        if (!empty($props)) {

            $db->setQuery("SELECT attribute_id FROM #_shop_attribute_property WHERE property_id IN(" . implode(',', $props) . ")");

            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }


            while ($o = $db->nextObject()) {
                $k = array_search($o->attribute_id, $req_atts);
                if ($k !== false) {
                    unset($req_atts[$k]);
                }
            }

            if (!empty($req_atts)) {
                Factory::getComponent('shop')->addError(__("Select required attributes!", 'com_shop'));
                return false;
            }

            unset($req_atts);
        }
        //if we are adding to cart variation, ignore the parent attributes
        //the variation itself is associated with them.
        if ($product->post->post_parent) {
            $props = array();
        }
        foreach ((array) $_SESSION['cart'] as $k => $row) {

            if ($row->id == $pid) {

                if ($product->post->post_parent) {
                    $tmp = array();
                } else {
                    $tmp = (array) ArrayHelper::compare((array) $row->props, (array) $props);
                }

                $tmp2 = ArrayHelper::compare((array) $row->files, (array) $files);
                if (empty($tmp) && empty($tmp2)) {
                    return $this->update_qty($k, $_SESSION['cart'][$k]->qty + $qty);
                }
            }
        }

        $item = new stdClass();

        $item->id = (int) $pid;
        $item->qty = (int) $qty;
        $item->props = (array) $props;

        $item->files = (array) $files;




        $k = is_array($_SESSION['cart']) && count($_SESSION['cart']) > 0 ? max(array_keys($_SESSION['cart'])) + 1 : 0;

        $values = array();
        $values[] = "('" . $db->secure(session_id()) . "'," .
                "'" . (int) $pid . "'," .
                "'product'," .
                "'" . (int) $qty . "'," .
                "'" . time() . "'," .
                "'" . (int) $k . "'," .
                "'" . time() . "')";

        foreach ((array) $props as $prop) {

            $values[] = "('" . $db->secure(session_id()) . "'," .
                    "'" . (int) $prop . "'," .
                    "'property'," .
                    "'" . (int) $qty . "'," .
                    "'" . time() . "'," .
                    "'" . (int) $k . "'," .
                    "'" . time() . "')";
        }


        $_SESSION['cart'][$k] = $item;

        $db = Factory::getDBO();

        $db->startTransaction();
        $db->setQuery("INSERT INTO #_shop_cart (session_id,product_id,section,qty,time,sess_group,last_access) VALUES" . implode(',', $values));

        if (!$db->getResource()) {
            $db->rollback();
            unset($_SESSION['cart'][$k]);
            throw new Exception($db->getErrorString());
        }

        $db->commit();

        return true;
    }

    public function remove($k) {
        $this->total_price = 0;
        if (array_key_exists($k, $_SESSION['cart'])) {
            $db = Factory::getDBO();
            $db->setQuery("DELETE FROM #_shop_cart WHERE session_id = '" . $db->secure(session_id()) . "' AND sess_group = " . (int) $k);

            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            unset($_SESSION['cart'][$k]);
            return true;
        }
        return false;
    }

    public function update_qty($k, $qty) {
        $this->total_price = 0;
        $return = false;
        if (array_key_exists($k, $_SESSION['cart'])) {

            if (0 >= $qty) {
                return $this->remove($k);
            }

            if ($qty == $_SESSION['cart'][$k]->qty) {
                return true;
            }

            if ($qty > $_SESSION['cart'][$k]->qty) {
                $new_qty = $qty - $_SESSION['cart'][$k]->qty;

                $model = model::getInstance('product', 'shop');
                $res = $model->is_variation_available($_SESSION['cart'][$k]->id, (array) $_SESSION['cart'][$k]->props);

                if ($res < $new_qty && $res !== true) {

                    if ($res > 0) {
                        $qty = $_SESSION['cart'][$k]->qty + $res;
                    } else {
                        return false;
                    }
                } else {
                    $return = true;
                }
            } else {
                $return = true;
            }

            $db = Factory::getDBO();
            $db->startTransaction();


            $db->setQuery("UPDATE #_shop_cart SET qty = {$qty} WHERE session_id = '" . $db->secure(session_id()) . "' AND sess_group = " . (int) $k);

            if (!$db->getResource()) {

                $db->rollback();
                throw new Exception($db->getErrorString());
            }

            $db->commit();

            $_SESSION['cart'][$k]->qty = (int) $qty;
            return $return;
        }
        return false;
    }

    public function get_formatted_price($type = 'total') {

        switch ($type) {
            case "grand_total":
                return Factory::getComponent('shop')->getHelper('price')->format($this->get_grand_total_price());
                break;
            default:
                return Factory::getComponent('shop')->getHelper('price')->format($this->get_total_price());
                break;
        }
    }

    public function set_payment($id) {

        $methods = $this->get_payment_methods();

        foreach ((array) $methods as $method) {

            if ($method->get_id() == $id) {
                $this->payment_id = $method->get_id();
                $this->payment = $method;
                return true;
            }
        }
        return false;
    }

    public function selected_payment_id() {
        return $this->payment_id;
    }

    public function selected_payment_method() {
        return $this->payment;
    }

    public function set_shipping($id) {

        $rates = $this->get_shipping_rates();

        foreach ((array) $rates as $rate) {
            if ($id == $rate->id) {

                $this->shipping_id = $id;
                $this->shipping_rate = $rate->rate;
                $this->shipping_vat = $rate->rate - $rate->raw_rate;
                return true;
            }
        }

        return false;
    }

    public function selected_shipping_id() {
        return $this->shipping_id;
    }

    public function selected_shipping_rate() {
        return $this->shipping_rate;
    }

    public function selected_shipping_vat() {

        return $this->shipping_vat;
    }

    public function get_total_price() {

        if (!empty($this->total_price))
            return $this->total_price;
        $sum = 0;

        while ($p = $this->get_item()) {

            $sum += $p->raw_price * $p->qty;
        }
        return $this->total_price = $sum;
    }

    public function get_order_vat() {

        if (!empty($this->order_vat))
            return $this->order_vat;
        $sum = 0;

        while ($p = $this->get_item()) {

            $sum += ( $p->vat ) * $p->qty;
        }
        return $this->order_vat = $sum;
    }

    public function get_grand_total_price() {

        $sum = $this->get_total_price();
        $sum += $this->get_order_vat();
        if ($this->need_shipping()) {

            $sum += $this->shipping_rate;
        }

        return $sum;
    }

    public function need_payment() {

        if ($this->get_grand_total_price() > 0)
            return true;

        return false;
    }

    public function is_empty() {
        return (bool) (isset($_SESSION['cart']) & empty($_SESSION['cart'])) ? true : false;
    }

    public function get_total_products() {

        $count = 0;

        foreach ((array) $_SESSION['cart'] as $p) {
            $count += $p->qty;
        }

        return (int) $count;
    }

    public function get_cart_taxes() {


        static $cache = array();

        if (count($cache)) {
            return $cache;
        }

        foreach ((array) $_SESSION['cart'] as $o) {

            $product = Factory::getComponent('shop')->getTable('product')->load($o->id);

            $rates = array();


            if (( Factory::getComponent('shop')->getParams()->get('vat') && $product->vat == 'global' ) || $product->vat == 'yes') {

                if ($product->tax_group_id) {

                    $rates = Factory::getComponent('shop')->getHelper('tax')->get_matched_rates(null, null, $product->tax_group_id);
                } else {
                    $rates = Factory::getComponent('shop')->getHelper('tax')->get_matched_rates();
                }

                if (count($rates)) {

                    $price = Factory::getComponent('shop')->getHelper('product_helper')->get_price($o->id, $o->props, $o->files)->raw;
                    $price = $price * $o->qty;


                    foreach ((array) $rates as $id => $rate) {

                        if (array_key_exists($id, $cache)) {
                            $cache[$id]->value += $price * $rate->rate;
                        } else {
                            $cache[$id] = clone $rate;
                            $cache[$id]->set("value", $price * $rate->rate);
                        }
                    }
                }
            }
        }

        return $cache;
    }

    public function get_item() {

        $o = current($_SESSION['cart']);
        if (empty($o)) {
            reset($_SESSION['cart']);
            return false;
        }

        $db = Factory::getDBO();

        $row = new stdClass();

        $row->group = key($_SESSION['cart']);
        next($_SESSION['cart']);

        $product = Factory::getComponent('shop')->getTable('product')->load($o->id);

        $row->type = $product->type;
        $row->sku = $product->sku;
        $row->qty = $o->qty;
        $row->id = $o->id;
        $tax_group = null;
        $row->files = $o->files;

        $row->raw_price = Factory::getComponent('shop')->getHelper('product_helper')->get_price($o->id, $o->props, $o->files)->raw;

        $row->price = $row->raw_price;

        if (( Factory::getComponent('shop')->getParams()->get('vat') && $product->vat == 'global' ) || $product->vat == 'yes') {

            if ($product->tax_group_id) {




                $row->price += $row->price * Factory::getComponent('shop')->getHelper('tax')->get_tax_rate(null, null, $product->tax_group_id);
            } else {
                $row->price += $row->price * Factory::getComponent('shop')->getHelper('tax')->get_tax_rate();
            }
        }
        $row->price_formatted = Factory::getComponent('shop')->getHelper('price')->format($row->price);
        $row->raw_price_formatted = Factory::getComponent('shop')->getHelper('price')->format($row->raw_price);
        $row->vat = $row->price - $row->raw_price;

        $row->name = $product->name;

        if (has_post_thumbnail($o->id)) {
            $img = wp_get_attachment_image_src(get_post_thumbnail_id($o->id), 'product_thumb');
            $row->thumb = $img[0];
            $meta = wp_get_attachment_metadata(get_post_thumbnail_id($o->id));
            $row->thumb_title = $meta['image_meta']['title'];
        } else {
            $row->thumb = '';
            $row->thumb_title = '';
        }

        $row->props = array();

        if (count($o->props)) {

            $db->setQuery("SELECT property_id,property_name,property_price,oprand FROM `#_shop_attribute_property`  WHERE property_id IN(" . implode(',', $o->props) . ") ORDER BY ordering ASC LIMIT " . count($o->props));

            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            $rows = (array) $db->loadObjectList('property_id');

            $c = 0;
            foreach ((array) $o->props as $k) {
                $p = $rows[$k][0];
                $row->props[$c] = new stdClass();
                $row->props[$c]->property_id = $p->property_id;
                $row->props[$c]->name = $p->property_name;

                if ($p->property_price > 0) {

                    $row->props[$c]->price = $p->oprand . round($p->property_price, 2);
                    $row->props[$c]->raw_price = round($p->property_price, 2);
                    $row->props[$c]->oprand = $p->oprand;
                } else {
                    $row->props[$c]->price = '';
                    $row->props[$c]->raw_price = 0;
                    $row->props[$c]->oprand = '';
                }

                $c++;
            }
        }
        return $row;
    }

    public function need_shipping() {
        if (!Factory::getComponent('shop')->getParams()->get('shipping')) {
            return false;
        } else {
            while ($p = $this->get_item()) {

                if ($p->type == 'regular') {
                    reset($_SESSION['cart']);

                    return true;
                }
            }
        }
    }

    public function get_shipping_rates() {

        static $rates = null;
        if (!is_null($rates))
            return $rates;
        $shipping_classes = array();
        $shipping_classes = apply_filters('register_shipping_class', $shipping_classes);

        foreach ((array) $shipping_classes as $r) {
            if ($r instanceof standart_shipping && $r->is_configured()) {
                $rates = array_merge((array) $rates, (array) $r->get_available_rates());
            }
        }

        return (array) $rates;
    }

    public function get_payment_methods() {


        static $rows = array();

        $customer = Factory::getComponent('shop')->getHelper('customer');

        $db = Factory::getDBO();

        $country = '';

        $wherestate = '';


        $country = $customer->get('billing_country', '');


        $wherecountry = '(FIND_IN_SET( "' . $country . '", countries ) OR countries="0" OR countries="" OR countries IS NULL )';

        $db->setQuery("SELECT class FROM `#_shop_methods` WHERE type='payment' AND {$wherecountry} ORDER BY method_order ASC, method_id DESC");
        if (!$db->getResource()) {
            throw new Exception($db->getErrorString());
        }
        $methods = array();
        while ($m = $db->nextObject()) {

            $methods[] = $m->class;
        }

        if (!empty($rows))
            return $rows;

        $payment_classes = array();
        $payment_classes = apply_filters('register_payment_class', $payment_classes);

        foreach ((array) $payment_classes as $class) {
            if ($class instanceof payment_method) {
                if ($class->is_active() && in_array(get_class($class), $methods)) {
                    $rows[array_search(get_class($class), $methods)] = $class;
                    ksort($rows);
                }
            }
        }
        return $rows;
    }

}
