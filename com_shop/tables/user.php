<?php

defined('_VALID_EXEC') or die('access denied');

class userTable extends table {

    private $__fields;
    public $ID = null;
    public $ship_to_billing = false;

    public function ship_to_billing() {
        return $this->ship_to_billing;
    }

    public function __construct() {
        parent::__construct('ID', '#_users');

        $helper = Factory::getComponent('shop')->getHelper('customer');

        $this->__fields['billing'] = $helper->get_billing_fields();
        $this->__fields['shipping'] = $helper->get_shipping_fields();

        foreach (array('billing', 'shipping') as $type) {

            while ($field = $this->__fields[$type]->get_field()) {
                $this->{$field->get_name()} = null;
            }
        }
    }

    public function get_shipping() {
        return $this->__fields['shipping'];
    }

    public function get_billing() {
        return $this->__fields['billing'];
    }

    public function load($id=null) {


        $this->ID = (int) $id;
        $fields = array_keys($this->getPublicFields());

        foreach ((array) $fields as $f) {
            if ($f == 'ID')
                continue;

            $this->{$f} = get_user_meta((int) $this->ID, "_" . $f, true);
        }



        foreach (array('billing', 'shipping') as $type) {

            while ($field = $this->__fields[$type]->get_field()) {

                $val = null;
                $fkey = null;


                $fkey = $field->get_name();

                $val = isset($this->{$fkey}) ? $this->{$fkey} : null;

                if ($field->get_name() == $type . "_country" && empty($val)) {
                    $val = Factory::getComponent('shop')->getParams()->get('shop_country');
                }


                $field->set_value($val);
            }



            if (isset($this->__fields[$type]->{$type . "_state"})) {
                if (isset($this->__fields[$type]->{$type . "_country"})) {

                    $this->__fields[$type]->{$type . "_state"}->set_country($this->__fields[$type]->{$type . "_country"}->get_value());
                } else {
                    $this->__fields[$type]->{$type . "_state"}->set_country(Factory::getComponent('shop')->getParams()->get('shop_country'));
                }
            }
        }



        return $this;
    }

    public function bind($from, $exclude = Array()) {

        parent::bind($from, $exclude);


        foreach (array('billing', 'shipping') as $type) {

            while ($field = $this->__fields[$type]->get_field()) {

                $val = null;
                $fkey = null;

                if ($type == 'shipping' && $this->ship_to_billing) {
                    $fkey = str_replace('shipping_', 'billing_', $field->get_name());
                } else {
                    $fkey = $field->get_name();
                }



                $val = isset($this->{$fkey}) ? $this->{$fkey} : null;

                if ($field->get_name() == $type . "_country" && empty($val)) {
                    $val = Factory::getComponent('shop')->getParams()->get('shop_country');
                }


                $field->set_value($val);
            }



            if (isset($this->__fields[$type]->{$type . "_state"})) {
                if (isset($this->__fields[$type]->{$type . "_country"})) {

                    $this->__fields[$type]->{$type . "_state"}->set_country($this->__fields[$type]->{$type . "_country"}->get_value());
                } else {
                    $this->__fields[$type]->{$type . "_state"}->set_country(Factory::getComponent('shop')->getParams()->get('shop_country'));
                }
            }
        }

        return $this;
    }

    public function store($safe_insert=false) {

        $model = Model::getInstance('user', 'shop');


        if (!$this->ID || !$model->is_user($this->ID)) {

            Factory::getComponent('shop')->addError(__('invalid user id', 'com_shop'));
            return false;
        }

        $fields = array_keys($this->getPublicFields());
        foreach ((array) $fields as $f) {
            if ($f == 'ID')
                continue;
            update_user_meta((int) $this->ID, "_" . $f, $this->{$f});
        }

        return $this;
    }

}
