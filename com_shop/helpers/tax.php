<?php

class tax {

    protected $gid = null;
    protected $country = null;
    protected $state = null;

    public function getInstance() {
        static $instance = null;

        if ($instance instanceof self)
            return $instance;
        return $instance = new self();
    }

    protected function __construct() {
        
    }

    public function get_tax_rate($country = null, $state = null, $gid = null) {

        $params = Factory::getApplication('shop')->getParams();
        if (!empty($country)) {
            $this->country = $country;
            $this->state = $state;
        } else {

            $customer = Factory::getApplication('shop')->getHelper('customer');


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
                    $this->country = $customer->get('shipping_country');
                    $this->state = $customer->get('shipping_state');
                    break;
            }
        }

        if (!is_numeric($gid) || empty($gid)) {
            $this->gid = (int) $params->get('shop_tax_group');
        } else {
            $this->gid = (int) $gid;
        }
        return $this->calc_tax_rate();
    }

    protected function calc_tax_rate() {


        $validate = Factory::getApplication('shop')->getHelper('validation');

        $params = Factory::getApplication('shop')->getParams();

        $country = $state = null;
        $db = Factory::getDBO();

        if ($this->gid) {

            switch ($params->get('vatType')) {

                case '0':

                    $country = $this->country;
                    $state = !empty($this->state) ? " = '" . $db->secure($this->state) . "'":" IS NULL ";

                    if (!empty($country)) {

                        $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} || tax_state IS NULL ) AND tax_group_id = {$this->gid} ORDER BY tax_state DESC LIMIT 1");
                        $tax_rate = (double) $db->loadResult();

                        if ($tax_rate) {

                            return $tax_rate / 100;
                        }
                    }

                    break;

                case "2":


                    if (!$this->country || $validate->is_in_eu($this->country)) {

                        $country = $params->get('shop_country');
                        if (!empty($country)) {
                            $state = $params->get('shop_state') ? " = '" . $db->secure($params->get('shop_state')) . "'" : " IS NULL ";

                            $db = Factory::getDBO();

                            $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND tax_state {$state} AND tax_group_id = {$this->gid} LIMIT 1");

                            $tax_rate = (double) $db->loadResult();

                            return $tax_rate / 100;
                        }
                    }
                    break;

                case "3":

                    $country = $this->country;
                    $state = !empty($this->state) ? " = '" . $db->secure($this->state) . "'" : " IS NULL ";


                    $db = Factory::getDBO();

                    $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND ( tax_state {$state} OR tax_state IS NULL ) AND tax_group_id = {$this->gid} ORDER BY tax_state DESC LIMIT 1");

                    $tax_rate = (double) $db->loadResult();


                    return $tax_rate / 100;


                    break;

                case "1":
                default:

                    $country = $params->get('shop_country');
                    $state = $params->get('shop_state') ? " = '" . $db->secure($params->get('shop_state')) . "'" : " IS NULL ";

                    $db = Factory::getDBO();

                    $db->setQuery("SELECT tax_rate FROM `#_shop_tax_rate` WHERE tax_country = '" . $db->secure($country) . "' AND tax_state {$state} AND tax_group_id = {$this->gid} LIMIT 1");

                    $tax_rate = (double) $db->loadResult();

                    return $tax_rate / 100;

                    break;
            }
        }
        return 0;
    }

}