<?php

class shipping_rateTable extends table {

    public $shipping_rate_id = null;
    public $shipping_rate_name = null;
    public $carrier = null;
    public $shipping_rate_country = null;
    public $shipping_rate_zip_start = 0;
    public $shipping_rate_zip_end = 0;
    public $shipping_rate_weight_start = 0;
    public $apply_vat = 'global';
    public $shipping_rate_weight_end = 0;
    public $shipping_rate_volume_start = 0;
    public $shipping_rate_volume_end = 0;
    public $shipping_rate_ordertotal_start = 0;
    public $shipping_rate_ordertotal_end = 0;
    public $shipping_rate_priority = 0;
    public $shipping_rate_value = 0;
    public $shipping_rate_package_fee = 0;
    public $shipping_location_info = 0;
    public $shipping_rate_length_start = 0;
    public $shipping_rate_length_end = 0;
    public $shipping_rate_width_start = 0;
    public $shipping_rate_width_end = 0;
    public $shipping_rate_height_start = 0;
    public $shipping_rate_height_end = 0;
    public $shipping_tax_group_id = null;
    public $shipping_rate_state = null;
    public $qty_multiply = "no";

    public function __construct() {
        $foreign_keys = array('carrier', 'shipping_tax_group_id');
        parent::__construct('shipping_rate_id', '#_shop_shipping_rate', $foreign_keys);
    }

    public function load($pk=null) {

        parent::load($pk);
        $this->shipping_rate_country = (array) explode(',', $this->shipping_rate_country);
        $this->shipping_rate_state = (array) explode(',', $this->shipping_rate_state);
        $this->shipping_rate_state = $this->shipping_rate_state;

        return $this;
    }

    public function bind($from, $exclude = Array()) {

        if (is_object($from) && !($from instanceof Input)) {
            $from = arrayHelper::fromObject($from, false);
        }

        if (array_key_exists('shipping_rate_country', $from) && is_array($from['shipping_rate_country'])) {
            $countries = (array) array_map(array($this->db, 'secure'), $from['shipping_rate_country']);
            $db = Factory::getDBO();
            if (!empty($countries)) {
                $db->setQuery("SELECT country_2_code FROM #_shop_country WHERE country_2_code IN('" . implode("','", $countries) . "')");

                if (!$db->getResource()) {
                    throw new Exception($db->getErrorString());
                }
                $this->shipping_rate_country = (array) $db->loadArray();
            }
            unset($countries, $from['shipping_rate_country']);
        } else {
            $this->shipping_rate_country = null;
        }

        if (array_key_exists('$shipping_rate_state', $from) && is_array($from['$shipping_rate_state'])) {
            $db = Factory::getDBO();

            $states = (array) array_map(array($db, 'secure'), $from['$shipping_rate_state']);

            if (!empty($states)) {

                $db->setQuery("SELECT state_2_code FROM #_shop_state WHERE state_id IN('" . implode("','", $states) . "')");

                if (!$db->getResource()) {
                    throw new Exception($db->getErrorString());
                }
                $this->shipping_rate_state = $db->loadArray();
            }

            unset($states, $from['shipping_rate_state']);
        } else {
            $this->shipping_rate_state = null;
        }


        return parent::bind($from);
    }

    public function store($safe_insert = false) {

        if (empty($this->shipping_rate_name)) {
            Factory::getComponent('shop')->setMessage(__('Enter rate label', 'com_shop'));
            return false;
        }
        $this->shipping_rate_country = empty($this->shipping_rate_country) ? null : implode(',', $this->shipping_rate_country);
        $this->shipping_rate_state = empty($this->shipping_rate_state) ? null : implode(',', $this->shipping_rate_state);

        return parent::store($safe_insert);
    }

}
