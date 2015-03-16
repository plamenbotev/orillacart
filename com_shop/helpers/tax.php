<?php

class tax {

    protected $gid = null;
    protected $country = null;
    protected $state = null;

    public static function getInstance() {
        static $instance = null;

        if ($instance instanceof self)
            return $instance;
        return $instance = new self();
    }

    protected function __construct() {
        
    }

    public function get_tax_rate($country = null, $state = null, $gid = null) {

        $params = Factory::getComponent('shop')->getParams();
        if (!empty($country)) {
            $this->country = $country;
            $this->state = $state;
        } else {

            $customer = Factory::getComponent('shop')->getHelper('customer');


            switch ($params->get('vatType')) {
                case '0':
                case '2':
                    $this->country = $customer->get('shipping_country');
                    $this->state = $customer->get('shipping_state');
                    break;

                case '3':
                    $this->country = $customer->get('billing_country');
                    $this->state = $customer->get('billing_state');
                    break;

                case '1':
                default:
                    $this->country = $params->get('shop_country');
                    $this->state = $params->get('shop_state');
                    break;
            }
        }

        if (!is_numeric($gid) || empty($gid)) {
            $this->gid = (int) $params->get('shop_tax_group');
        } else {
            $this->gid = (int) $gid;
        }


        $validate = Helper::getInstance('validation', 'shop');
        if (!$validate->country_has_states($this->country) || !$validate->state_in_country($this->state, $this->country)) {
            $this->state = null;
        }

        return $this->calc_tax_rate();
    }

    public function get_matched_rates($country = null, $state = null, $gid = null) {

        $params = Factory::getComponent('shop')->getParams();
        if (!empty($country)) {
            $this->country = $country;
            $this->state = $state;
        } else {

            $customer = Factory::getComponent('shop')->getHelper('customer');


            switch ($params->get('vatType')) {
                case '0':
                case '2':
                    $this->country = $customer->get('shipping_country');
                    $this->state = $customer->get('shipping_state');
                    break;

                case '3':
                    $this->country = $customer->get('billing_country');
                    $this->state = $customer->get('billing_state');
                    break;

                case '1':
                default:

                    $this->country = $params->get('shop_country');
                    $this->state = $params->get('shop_state');
                    break;
            }
        }

        if (!is_numeric($gid) || empty($gid)) {

            $this->gid = (int) $params->get('shop_tax_group');
        } else {

            $this->gid = (int) $gid;
        }

        $validate = Helper::getInstance('validation', 'shop');
        if (!$validate->country_has_states($this->country) || !$validate->state_in_country($this->state, $this->country)) {
            $this->state = null;
        }

        $country = $state = null;
        $db = Factory::getDBO();

        $res = array();


        if ($this->gid) {

            switch ($params->get('vatType')) {

                case '0':

                    $country = $this->country;
                    $state = !empty($this->state) ? " = '" . $db->secure($this->state) . "'" : " IS NULL OR tax_state =''";

                    if (!empty($country)) {

                        $db->setQuery("SELECT * FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} || tax_state IS NULL ) AND tax_group_id = {$this->gid} ORDER BY priority ASC,tax_rate_id ASC ");
                        $tax_rates = $db->loadObjectList();


                        foreach ((array) $tax_rates as $rate) {

                            $res[$rate->tax_rate_id] = new BObject();
                            if (!empty($rate->tax_name)) {
                                $res[$rate->tax_rate_id]->set("name", $rate->tax_name);
                            }
                            $res[$rate->tax_rate_id]->set("rate", $rate->tax_rate / 100);
                        }
                        return $res;
                    }

                    break;

                case "2":


                    if (!$this->country || $validate->is_in_eu($this->country)) {

                        $country = $params->get('shop_country');
                        $state = $params->get('shop_state') ? " = '" . $db->secure($params->get('shop_state')) . "'" : " IS NULL OR tax_state = ''";

                        if (!empty($country)) {

                            $db->setQuery("SELECT * FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} || tax_state IS NULL ) AND tax_group_id = {$this->gid} ORDER BY priority ASC,tax_rate_id ASC ");
                            $tax_rates = $db->loadObjectList();


                            foreach ((array) $tax_rates as $rate) {

                                $res[$rate->tax_rate_id] = new BObject();
                                if (!empty($rate->tax_name)) {
                                    $res[$rate->tax_rate_id]->set("name", $rate->tax_name);
                                }
                                $res[$rate->tax_rate_id]->set("rate", $rate->tax_rate / 100);
                            }
                            return $res;
                        }
                    }
                    break;

                case "3":

                    $country = $this->country;
                    $state = !empty($this->state) ? " = '" . $db->secure($this->state) . "'" : " IS NULL OR tax_state =''";


                    $db = Factory::getDBO();

                    $db->setQuery("SELECT * FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} OR tax_state IS NULL ) AND tax_group_id = {$this->gid} ORDER BY priority ASC,tax_rate_id ASC");

                    $tax_rates = $db->loadObjectList();

                    foreach ((array) $tax_rates as $rate) {

                        $res[$rate->tax_rate_id] = new BObject();
                        if (!empty($rate->tax_name)) {
                            $res[$rate->tax_rate_id]->set("name", $rate->tax_name);
                        }
                        $res[$rate->tax_rate_id]->set("rate", $rate->tax_rate / 100);
                    }


                    return $res;


                    break;

                case "1":
                default:

                    $country = $params->get('shop_country');
                    $state = $params->get('shop_state') ? " = '" . $db->secure($params->get('shop_state')) . "'" : " IS NULL OR tax_state =''";

                    $db = Factory::getDBO();

                    $db->setQuery("SELECT * FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND tax_state {$state} AND tax_group_id = {$this->gid} ORDER BY priority ASC,tax_rate_id ASC");

                    $tax_rates = $db->loadObjectList();

                    foreach ((array) $tax_rates as $rate) {

                        $res[$rate->tax_rate_id] = new BObject();
                        if (!empty($rate->tax_name)) {
                            $res[$rate->tax_rate_id]->set("name", $rate->tax_name);
                        }
                        $res[$rate->tax_rate_id]->set("rate", $rate->tax_rate / 100);
                    }
                    return $res;

                    break;
            }
        }

        return $res;
    }

    protected function calc_tax_rate() {


        $validate = Factory::getComponent('shop')->getHelper('validation');

        $params = Factory::getComponent('shop')->getParams();

        $country = $state = null;
        $db = Factory::getDBO();

        if ($this->gid) {

            switch ($params->get('vatType')) {

                case '0':

                    $country = $this->country;
                    $state = !empty($this->state) ? " = '" . $db->secure($this->state) . "'" : " IS NULL OR tax_state = ''";

                    if (!empty($country)) {

                        $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} || tax_state IS NULL ) AND tax_group_id = {$this->gid} ");
                        $tax_rates = $db->loadArray();

                        if (count($tax_rates) > 1) {

                            $tax_rate = (double) array_sum($tax_rates);

                            return $tax_rate / 100;
                        } else {


                            return $tax_rates[0] / 100;
                        }
                    }

                    break;

                case "2":


                    if (!$this->country || $validate->is_in_eu($this->country)) {

                        $country = $params->get('shop_country');
                        if (!empty($country)) {
                            $state = $params->get('shop_state') ? " = '" . $db->secure($params->get('shop_state')) . "'" : " IS NULL OR tax_state = ''";

                            $db = Factory::getDBO();

                            $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND tax_state {$state} AND tax_group_id = {$this->gid} ");

                            $tax_rates = $db->loadArray();

                            if (count($tax_rates) > 1) {

                                $tax_rate = (double) array_sum($tax_rates);

                                return $tax_rate / 100;
                            } else {


                                return $tax_rates[0] / 100;
                            }
                        }
                    }
                    break;

                case "3":

                    $country = $this->country;
                    $state = !empty($this->state) ? " = '" . $db->secure($this->state) . "'" : " IS NULL OR tax_state = ''";


                    $db = Factory::getDBO();

                    $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} OR tax_state IS NULL ) AND tax_group_id = {$this->gid} ");

                    $tax_rates = $db->loadArray();

                    if (count($tax_rates) > 1) {

                        $tax_rate = (double) array_sum($tax_rates);

                        return $tax_rate / 100;
                    } else {


                        return $tax_rates[0] / 100;
                    }


                    break;

                case "1":
                default:

                    $country = $params->get('shop_country');
                    $state = $params->get('shop_state') ? " = '" . $db->secure($params->get('shop_state')) . "'" : " IS NULL OR tax_state = ''";

                    $db = Factory::getDBO();

                    $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND tax_state {$state} AND tax_group_id = {$this->gid} ");

                    $tax_rates = $db->loadArray();

                    if (count($tax_rates) > 1) {

                        $tax_rate = (double) array_sum($tax_rates);

                        return $tax_rate / 100;
                    } else {


                        return $tax_rates[0] / 100;
                    }

                    break;
            }
        }

        return 0;
    }

}
