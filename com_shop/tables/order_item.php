<?php

class order_itemTable extends table {

    public $order_item_id = null;
    public $order_id = null;
    public $product_id = null;
    public $product_type = 'regular';
    public $order_item_sku = null;
    public $order_item_name = null;
    public $product_quantity = null;
    public $product_item_price = null;
    public $vat = null;
    public $stockrooms = null;
    public $access_granted = '0000-00-00 00:00:00';
    public $product_length = null;
    public $product_width = null;
    public $product_height = null;
    public $product_volume = null;
    public $product_diameter = null;
    public $product_weight = null;

    public function __construct() {

        $foreign_keys = array('order_id');
        parent::__construct("order_item_id", "#_shop_order_item", $foreign_keys);
    }

    public function store($safe_insert = false) {

        if (!empty($this->stockrooms)) {
            $this->stockrooms = json_encode($this->stockrooms);
        } else {
            $this->stockrooms = null;
        }

        return parent::store($safe_insert);
    }

    public function load($id=null) {
        parent::load($id);

        if (!empty($this->stockrooms)) {
            $this->stockrooms = json_decode($this->stockrooms, true);
        }

        return $this;
    }

}
