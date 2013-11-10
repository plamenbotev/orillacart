<?php

defined('_VALID_EXEC') or die('access denied');

class stockroomTable extends table {

    public $id = null;
    public $name = '';
    public $desc = '';
    public $published = 'no';
    public $min_del_time = null;
    public $max_del_time = null;
    public $delivery_time = 'day';

    public function __construct() {
        parent::__construct('id', '#_shop_stockroom');
    }

}