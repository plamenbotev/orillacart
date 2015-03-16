<?php

class shopViewWidgets extends view {

    public function __construct() {
        parent::__construct();
    }

    public function cart_widget() {
        $this->assign('cart', Factory::getComponent('shop')->getHelper('cart'));
        $this->loadTemplate('cart_widget');
    }

    public function cart_ajax_data() {
        $this->assign('cart', Factory::getComponent('shop')->getHelper('cart'));
        $this->loadTemplate('cart_widget_ajax');
    }

}
