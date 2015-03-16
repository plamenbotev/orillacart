<?php

class orderTable extends table {

    public $ID = null;
    public $title = '';
    public $customer_id = null;
    public $order_total = 0;
    public $order_subtotal = 0;
    public $order_tax = 0;
    public $order_shipping = 0;
    public $order_shipping_tax = 0;
    public $ship_method_id = null;
    public $order_comments = '';
    public $payment_method = null;
    public $tid = null;
    public $currency = null;
    public $currency_sign = null;
    public $shipping_name = null;
    public $shipping_rate_name = null;
    public $payment_name = null;
    public $volume_unit = null;
    public $weight_unit = null;
    public $volume = null;
    public $length = null;
    public $width = null;
    public $height = null;
    public $post_password = null;
    public $weight = null;
    //default billing fields
    public $billing_first_name = null;
    public $billing_last_name = null;
    public $billing_address = null;
    public $billing_city = null;
    public $billing_zipcode = null;
    public $billing_country = null;
    public $billing_state = null;
    public $billing_email = null;
    public $billing_phone = null;
    //default shipping fields
    public $shipping_first_name = null;
    public $shipping_last_name = null;
    public $shipping_address = null;
    public $shipping_city = null;
    public $shipping_zipcode = null;
    public $shipping_country = null;
    public $shipping_state = null;

    public function __construct() {

        //@TODO init all added fields


        parent::__construct('ID', '#_posts');
    }

    public function store($safe_insert = false) {



        $data = array(
            'ID' => $this->ID,
            'post_type' => 'shop_order',
            'post_title' => $this->title ? $this->title : 'Order &ndash; ' . date('F j, Y @ h:i A'),
            'post_status' => 'publish',
            'ping_status' => 'closed',
            'post_excerpt' => $this->order_comments,
            'post_author' => 1,
            'post_password' => $this->post_password ? $this->post_password : uniqid('order_') // order key
        );

        if (!$this->pk()) {
            $id = wp_insert_post($data);
        } else {
            $id = wp_update_post($data);
        }
        if (!$id) {
            return false;
        }

        $this->ID = $id;

        $fields = $this->getPublicFields();

        foreach ((array) $fields as $f => $v) {

            if (in_array($f, array('ID', 'order_comments', 'title', 'post_password', 'cdate')))
                continue;
            if (empty($v))
                $v = '';
            update_post_meta((int) $this->ID, "_{$f}", $v);
        }

        return $this;
    }

    public function load($id=null) {

        $post = get_post($id);

        if (!$post)
            return $this;

        $this->ID = $post->ID;
        $this->order_comments = $post->post_excerpt;
        $this->title = $post->post_title;
        $this->post_password = $post->post_password;
        $this->cdate = $post->post_date;

        $fields = $this->getPublicFields();
        $meta = get_post_custom($this->ID);


        foreach ((array) $fields as $f => $v) {

            if (in_array($f, array('ID', 'order_comments', 'title', 'post_password', 'cdate')))
                continue;

            if (array_key_exists("_" . $f, $meta)) {
                $this->{$f} = $meta["_" . $f][0];
            }
        }

        $customer = Factory::getComponent('shop')->getHelper('customer');

        $billing = $customer->get_billing_fields();

        while ($f = $billing->get_field()) {
            if (array_key_exists("_" . $f->get_name(), $meta)) {
                $this->{$f->get_name()} = $meta["_" . $f->get_name()][0];
            }
        }

        if (isset($billing->billing_state)) {

            $billing->billing_state->set_country($billing->billing_country->get_value());
        }

        $shipping = $customer->get_shipping_fields();

        while ($f = $shipping->get_field()) {
            if (array_key_exists("_" . $f->get_name(), $meta)) {
                $this->{$f->get_name()} = $meta["_" . $f->get_name()][0];
            }
        }

        if (isset($shipping->shipping_state)) {

            $shipping->shipping_state->set_country($shipping->shipping_country->get_value());
        }


        return $this;
    }

}
