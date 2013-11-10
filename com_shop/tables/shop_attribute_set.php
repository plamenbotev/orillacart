<?php

defined('_VALID_EXEC') or die('access denied');

class shop_attribute_setTable extends table {

    public $attribute_set_id = null;
    public $attribute_set_name = null;
    public $published = 'no';

    public function __construct() {
        parent::__construct('attribute_set_id', '#_shop_attribute_set');
    }

}