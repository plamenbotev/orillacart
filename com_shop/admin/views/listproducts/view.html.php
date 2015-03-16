<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewListProducts extends view {

    public function fill_column() {
         $this->loadTemplate('listproducts');
    }

}
