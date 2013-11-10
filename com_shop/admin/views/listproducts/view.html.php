<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewListProducts extends view {

    public function display() {
        
    }

    public function fill_column() {
        parent::display('listproducts');
    }

}