<?php

class standart_shipping implements SelfRegisterable {

    protected static $order = null;

    final static public function in_order(orderTable $o) {
        self::$order = $o;
    }

    final protected function get_shipping_address() {
        if (!is_null(self::$order) && self::$order->pk()) {
            return Factory::getComponent('shop')->getHelper('order')->get_order_shipping(self::$order->pk());
        } else {
            return Factory::getComponent('shop')->getHelper('customer')->get_shipping();
        }
    }

    final protected function get_billing_address() {


        if (!is_null(self::$order) && self::$order->pk()) {

            return Factory::getComponent('shop')->getHelper('order')->get_order_billing(self::$order->pk());
        } else {
            return Factory::getComponent('shop')->getHelper('customer')->get_billing();
        }
    }

    final protected function get_tax_rate($gid = null) {

        switch (Factory::getComponent('shop')->getParams()->get('vatType')) {

            case '3':
                $billing = $this->get_billing_address();

                if ($billing) {

                    $country = null;
                    $state = null;

                    if (isset($billing->billing_country)) {
                        $country = $billing->billing_country->get_value();
                    }

                    if (isset($billing->billing_state)) {
                        $state = $billing->billing_state->get_value();
                    }

                    return Factory::getComponent('shop')->getHelper('tax')->get_tax_rate($country, $state, $gid);
                }
                break;

            case '0':
            case '1':
            case '2':
            default:

                $shipping = $this->get_shipping_address();
                if ($shipping) {

                    $country = null;
                    $state = null;

                    if (isset($shipping->shipping_country)) {
                        $country = $shipping->shipping_country->get_value();
                    }

                    if (isset($shipping->shipping_state)) {
                        $state = $shipping->shipping_state->get_value();
                    }

                    return Factory::getComponent('shop')->getHelper('tax')->get_tax_rate($country, $state, $gid);
                }
                break;
        }

        return 0;
    }

    public function get_currency() {

        if (!is_null(self::$order) && self::$order->pk()) {
            return self::$order->currency;
        } else {
            return Factory::getComponent('shop')->getParams()->get('currency');
        }
    }

    public function is_configured() {
        $db = Factory::getDBO();

        $where = " class IS NULL";
        if ($this->get_class_name() != 'standart_shipping') {

            $class = $db->secure($this->get_class_name());
            $where = " class = '" . $class . "' ";
        }

        $db->setQuery("SELECT COUNT(*) FROM #_shop_methods WHERE type='shipping' AND " . $where);
        if (!$db->getResource()) {
            throw new Exception($db->getErrorString());
        }

        return (int) $db->loadResult();
    }

    final protected function get_volume_shipping() {

        static $res = array();

        if (!empty($res))
            return $res;


        if (!is_null(self::$order) && self::$order->pk()) {


            $db = Factory::getDBO();

            $db->setQuery("SELECT 
                MAX(product_length) AS l_max, 
                SUM(product_length) AS l_total,
                MAX(product_width) AS w_max, 
                SUM(product_width) AS w_total,
                MAX(product_height) AS h_max, 
                SUM(product_height) AS h_total,
                MAX(product_volume) AS v_max, 
                SUM(product_volume) AS v_total
                FROM #_shop_order_item WHERE order_id = " . (int) self::$order->pk());

            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            $row = $db->nextObject();


            // 3 cases are available for shipping boxes
            $cases = array();
            $cases[0]['length'] = $row->l_max;
            $cases[0]['width'] = $row->w_max;
            $cases[0]['height'] = $row->h_total;

            $cases[1]['length'] = $row->l_max;
            $cases[1]['width'] = $row->w_total;
            $cases[1]['height'] = $row->h_max;

            $cases[2]['length'] = $row->l_total;
            $cases[2]['width'] = $row->w_max;
            $cases[2]['height'] = $row->h_max;

            return $cases;
        } else {
            $cart = Factory::getComponent('shop')->getHelper('cart');
            return $cart->getProductVolumeShipping();
        }
    }

    final protected function get_volume_unit() {
        if (!is_null(self::$order) && self::$order->pk()) {
            return self::$order->volume_unit;
        } else {
            return Factory::getComponent('shop')->getParams()->get('default_volume_unit');
        }
    }

    final protected function get_weight_unit() {
        if (!is_null(self::$order) && self::$order->pk()) {
            return self::$order->weight_unit;
        } else {
            return Factory::getComponent('shop')->getParams()->get('default_weight_unit');
        }
    }

    final protected function get_order_dim() {

        if (!is_null(self::$order) && self::$order->pk()) {

            $db = Factory::getDBO();
            $db->setQuery("SELECT SUM(product_quantity) FROM `#_shop_order_item` WHERE product_type='regular' AND order_id = " . (int) self::$order->pk());
            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            $res = array(
                "totalquantity" => (int) $db->loadResult(),
                "totalweight" => self::$order->weight,
                "totalvolume" => self::$order->volume,
                "totallength" => self::$order->length,
                "totalheight" => self::$order->height,
                "totalwidth" => self::$order->weight
            );

            return $res;
        } else {
            $cart = Factory::getComponent('shop')->getHelper('cart');
            return $cart->getCartItemDimention();
        }
    }

    final protected function get_order_subtotal() {
        if (!is_null(self::$order) && self::$order->pk()) {
            return self::$order->order_subtotal;
        } else {
            return Factory::getComponent('shop')->getHelper('cart')->get_total_price();
        }
    }

    final protected function get_total_qty() {

        $db = Factory::getDBO();
        if (!is_null(self::$order) && self::$order->pk()) {
            $db->setQuery("SELECT SUM(product_quantity) FROM `#_shop_order_item` WHERE order_id = " . (int) self::$order->pk() . " LIMIT 1 ");
            return (int) $db->loadResult();
        } else {
            return Factory::getComponent('shop')->getHelper('cart')->get_total_products();
        }
    }

    final public function get_class_name() {

        return get_class($this);
    }

    final protected function get_carriers() {

        static $rows = null;

        if (!is_null($rows))
            return $rows;

        $db = Factory::getDBO();

        $where = "";
        if ($this->get_class_name() == __CLASS__) {
            $where = "WHERE type = 'shipping' AND class IS NULL";
        } else {
            $where = "WHERE type = 'shipping' AND class ='" . $db->secure($this->get_class_name()) . "'";
        }

        $sql = "SELECT * FROM `#_shop_methods` {$where} ORDER BY method_order";

        $db->setQuery($sql);

        if (!$db->getResource()) {

            throw new Exception($db->getErrorString());
        }

        $rows = (array) $db->loadObjectList();

        foreach ((array) $rows as $k => $v) {

            $rows[$k]->params = new Registry($v->params);
        }

        return $rows;
    }

    public function get_rates() {

        $db = Factory::getDBO();

        $cart = Factory::getComponent('shop')->getHelper("cart");

        $customer = Factory::getComponent('shop')->getHelper('customer');



        $totaldimention = $this->get_order_dim();

        $shipping = $this->get_shipping_address();

        $customer = Factory::getComponent('shop')->getHelper('customer');

        $params = Factory::getComponent('shop')->getParams();


        $order_subtotal = $this->get_order_subtotal();




        $weighttotal = $totaldimention['totalweight'];
        $volume = $totaldimention['totalvolume'];


        $country = '';
        $state = '';


        $wherestate = '';
        if (isset($shipping->shipping_country)) {

            $country = $shipping->shipping_country->get_value();

            if (isset($shipping->shipping_state)) {
                $state = $shipping->shipping_state->get_value();
            }
        }

        $rows = $this->get_carriers();

        if (empty($rows)) {
            return array();
        }

        $ids = array();

        $rates = array();

        $conditions = array(
            0 => array('AND', ''),
            1 => array('OR', ''),
            2 => array('AND', 'NOT'),
            3 => array('OR', 'NOT')
        );



        foreach ((array) $rows as $r) {


            if ($country) {
                $wherecountry = $conditions[$r->params->get('country', 0)][0] . ' ( ' . $conditions[$r->params->get('country', 0)][1] . ' FIND_IN_SET( "' . $country . '", shipping_rate_country ) OR shipping_rate_country="0" OR shipping_rate_country="" OR shipping_rate_country IS NULL)';
            } else {
                $wherecountry = ''; //$conditions[$r->params->get('country', 0)][0].' ( ' . $conditions[$r->params->get('country', 0)][1] . ' FIND_IN_SET( "' . $params->get('shop_country') . '", shipping_rate_country ) )';
            }

            if ($state) {
                $wherestate = $conditions[$r->params->get('state', 0)][0] . ' ( ' . $conditions[$r->params->get('state', 0)][1] . ' FIND_IN_SET( "' . $state . '", shipping_rate_state ) OR shipping_rate_state="0" OR shipping_rate_state="" OR shipping_rate_state IS NULL )';
            } else {
                $wherestate = '';
            }


            $sql = "SELECT sr.*,c.* FROM `#_shop_shipping_rate` as sr
			
			INNER JOIN `#_shop_methods` as c ON sr.carrier = c.method_id
			WHERE sr.carrier = {$r->method_id} 
                        
                        {$wherecountry}
                        {$wherestate}
                        AND c.type='shipping'
			" . $conditions[$r->params->get('volume', 0)][0] . "
                        ( " . $conditions[$r->params->get('volume', 0)][1] . " (shipping_rate_volume_start <= '{$volume}' AND  (shipping_rate_volume_end >= '{$volume}' OR shipping_rate_volume_end = 0) ) )
			" . $conditions[$r->params->get('total', 0)][0] . "
                        (" . $conditions[$r->params->get('total', 0)][1] . " (shipping_rate_ordertotal_start <= '{$order_subtotal}' AND  (shipping_rate_ordertotal_end >= '{$order_subtotal}' OR shipping_rate_ordertotal_end = 0) ))
			" . $conditions[$r->params->get('weight', 0)][0] . "
                        (" . $conditions[$r->params->get('weight', 0)][1] . " (shipping_rate_weight_start <= '{$weighttotal}' AND  ( shipping_rate_weight_end >= '{$weighttotal}'  OR shipping_rate_weight_end = 0)))
			" . $conditions[$r->params->get('width', 0)][0] . "
                         (" . $conditions[$r->params->get('width', 0)][1] . " (shipping_rate_width_start <= '" . $totaldimention['totalwidth'] . "' AND  (shipping_rate_width_end >= '" . $totaldimention['totalwidth'] . "'  OR shipping_rate_width_end = 0)))
			                        
                         " . $conditions[$r->params->get('length', 0)][0] . "
                         (" . $conditions[$r->params->get('length', 0)][1] . " (shipping_rate_length_start <= '" . $totaldimention['totallength'] . "' AND  (shipping_rate_length_end >= '" . $totaldimention['totallength'] . "'  OR shipping_rate_length_end = 0)))
			
                          " . $conditions[$r->params->get('height', 0)][0] . "
                         (" . $conditions[$r->params->get('height', 0)][1] . " (shipping_rate_height_start <= '" . $totaldimention['totalheight'] . "' AND  (shipping_rate_height_end >= '" . $totaldimention['totalheight'] . "' OR shipping_rate_height_end = 0)))
			         
                         
                         
                        ORDER BY sr.shipping_rate_priority,shipping_rate_value ";

            $db->setQuery($sql);

            if (!$db->getResource()) {

                throw new Exception($db->getErrorString());
            }

            $rates = (array) array_merge((array) $rates, (array) $db->loadObjectList());
        }




        return $rates;
    }

    public function get_available_rates() {

        $order = Factory::getComponent('shop')->getHelper('order');
        $price = Factory::getComponent('shop')->getHelper('price');
        $params = Factory::getComponent('shop')->getParams();
        $rates = $this->get_rates();


        $res = array();

        foreach ((array) $rates as $rate) {

            $row = new stdClass();

            if ($rate->qty_multiply == "yes") {
                $row->rate = (double) $rate->shipping_rate_value * $this->get_total_qty();
                $row->raw_rate = (double) $rate->shipping_rate_value * $this->get_total_qty();
            } else {
                $row->rate = (double) $rate->shipping_rate_value;
                $row->raw_rate = (double) $rate->shipping_rate_value;
            }

            $gid = !$rate->shipping_tax_group_id ? $params->get('shop_tax_group') : $rate->shipping_tax_group_id;

            switch (strtolower($rate->apply_vat)) {


                case "yes":

                    $row->rate += $row->rate * $this->get_tax_rate($gid);

                    break;

                case "no":
                    break;

                case "global":
                default:

                    if ($params->get('vat')) {

                        $row->rate += $row->rate * $this->get_tax_rate($gid);
                    }


                    break;
            }


            $row->name = strings::htmlentities($rate->name . " - " . $rate->shipping_rate_name . " (+" . $price->format($row->rate) . ") ");

            $id = new stdClass();

            $id->class = $this->get_class_name();
            $id->carrier_id = $rate->carrier;
            $id->rate_name = $rate->shipping_rate_name;
            $id->rate_id = $rate->shipping_rate_id;
            $id->rate = $row->rate;
            $id->vat = $row->rate - $row->raw_rate;

            $id = json_encode($id);



            $row->id = $order->encryptShipping($id);

            $res[] = $row;
        }
        return $res;
    }

    public function print_options() {

        $input = Factory::getApplication()->getInput();
        $id = $input->get('method_id', 0, "INT");

		$params = $input->get("params",null,"ARRAY");
		
		if(!empty($params)){
				$params = new Registry($params);
		}else{
			 $carrier = Table::getInstance('carrier', 'shop')->load($id);
			 $params = $carrier->params;
		}
		
      
        $view = view::getInstance("shipping", "shop");
        $view->assign('params', $params);
        $view->standart_shipping_params();
    }

    public function save_options($id) {

        $input = Factory::getApplication()->getInput();

        $params = $input->get('params', array(), "ARRAY");
        $carrier = Table::getInstance('carrier', 'shop')->load($id);
        $carrier->params = $params;

        $carrier->store();
    }

    public static function register(array $methods) {
        $methods[] = new self();
        return $methods;
    }

}
