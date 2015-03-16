<?php

defined('_VALID_EXEC') or die('access denied');

class tax_rateTable extends table {

    public $tax_rate_id = null;
    public $tax_state = null;
    public $tax_country = null;
    public $tax_rate = null;
    public $tax_group_id = null;
    public $tax_name = null;
    public $priority = 1;

    public function __construct() {
        $foreign_keys = array('tax_state', 'tax_country', 'tax_group_id');
        parent::__construct('tax_rate_id', '#_shop_tax_rate', $foreign_keys);
    }

}
