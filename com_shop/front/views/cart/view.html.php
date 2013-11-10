<?php

class shopViewCart extends view {

    public function display() {

        Factory::getMainframe()->addscript('jquery');
        Factory::getMainframe()->addscript('json', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.json-2.2.js");
        Factory::getMainframe()->setPageTitle(__("Your Cart","com_shop"));
        $params = Factory::getApplication('shop')->getParams();

        parent::display("cart");
    }

    public function checkout() {
        
        Factory::getMainframe()->setPageTitle(__("Checkout","com_shop"));

        Factory::getMainframe()->addscript('jquery');
        Factory::getMainframe()->addstyle('chosen', Factory::getApplication('shop')->getAssetsUrl() . '/chosen.css');
        Factory::getMainframe()->addscript('chosen', Factory::getApplication('shop')->getAssetsUrl() . '/js/chosen.jquery.js');
        Factory::getMainframe()->addScript('jquery-validate', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.validate.js");
        Factory::getMainframe()->addscript('json', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.json-2.2.js");
        Factory::getMainframe()->addscript('block', Factory::getApplication('shop')->getAssetsUrl() . "/js/block.js");
        Factory::getMainframe()->addscript('ckeckout', Factory::getApplication('shop')->getAssetsUrl() . "/js/checkout.js");



        parent::display("checkout");
    }

    public function country_states_select() {
        parent::display('country_states_select');
    }

    public function update_totals() {


        $cart = Factory::getApplication('shop')->getHelper('cart');


        if ($cart->is_empty()) {
            die("<div>Your session has expired and the cart is empty!</div>");
        }

        parent::display('order');
    }

    public function ccard_form() {

        Factory::getMainframe()->setPageTitle(__("Provide payment details","com_shop"));
        parent::display('ccard_form');
    }

    public function do_payment() {

        $this->gateway->do_payment($this->order_id, $this->order);
    }

    public function order_receipt() {
        Factory::getMainframe()->setPageTitle(__("Order Receipt","com_shop"));
        Factory::getMainframe()->addStyle('receipt_styles', Factory::getApplication('shop')->getAssetsUrl() . "/receipt-view.css");
        parent::display('order_receipt');
    }

}