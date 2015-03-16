<?php

class order_attribute_itemTable extends table {

    public $order_att_item_id = null;
    public $order_item_id = null;
    public $order_id = null;
    public $section_id = null;
    public $section = null;
    public $parent_section_id = null;
    public $section_name = null;
    public $section_price = null;
    public $section_oprand = null;
    public $stockrooms = null;
    public $downloads_remaining = null;
    public $expires = null;

    public function __construct() {

        $foreign_keys = array('order_item_id', 'order_id');
        parent::__construct("order_att_item_id", "#_shop_order_attribute_item", $foreign_keys);
    }

    public function store($safe_insert = false) {

        if (!empty($this->stockrooms)) {
            $this->stockrooms = json_encode($this->stockrooms);
        } else
            $this->stockrooms = null;

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
