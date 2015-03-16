<?php

defined('_VALID_EXEC') or die('access denied');

class shop_attributeTable extends table {

    public $attribute_id = null;
    public $attribute_name = '';
    public $attribute_required = 'no';
    public $hide_attribute_price = 'no';
    public $product_id = null;
    public $ordering = null;
    public $attribute_set_id = null;
    public $stockroom_id = null;

    public function __construct() {
        parent::__construct('attribute_id', '#_shop_attribute');
    }

}
