<?php

defined('_VALID_EXEC') or die('access denied');

class tax_groupTable extends table {

    public $tax_group_id = null;
    public $tax_group_name = '';
    public $published = 1;

    public function __construct() {
        parent::__construct('tax_group_id', '#_shop_tax_group');
    }
    
    public function bind($from){
       if( (is_array($from) && !array_key_exists('published',$from)) ||
            (is_object($from) && !property_exists($from, 'published')) ){
           $this->published = 0;
       }
       
       return parent::bind($from);
    }

}