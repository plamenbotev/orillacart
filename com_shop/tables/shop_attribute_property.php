<?php

defined('_VALID_EXEC') or die('access denied');

class shop_attribute_propertyTable extends table {

    public $property_id = '';
    public $attribute_id = '';
    public $property_name = '';
    public $property_price = '';
    public $oprand = '+';
    public $ordering = '';
  
    public function __construct() {
        $foreign_keys = array('attribute_id');
        parent::__construct('property_id', '#_shop_attribute_property', $foreign_keys);
    }

}