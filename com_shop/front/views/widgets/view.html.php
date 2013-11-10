<?php

class shopViewWidgets extends view {

    public function __construct() {
        parent::__construct();
    }

    public function cart_widget() {
        $this->assign('cart', Factory::getApplication('shop')->getHelper('cart'));
        parent::display('cart_widget');
    }

    public function cart_ajax_data() {
        $this->assign('cart', Factory::getApplication('shop')->getHelper('cart'));
        parent::display('cart_widget_ajax');
    }

}