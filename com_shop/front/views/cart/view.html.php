<?php

class shopViewCart extends view {

    public function display() {

        Factory::getHead()->addscript('jquery');
        Factory::getHead()->addscript('json', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.json-2.2.js");
        Factory::getHead()->setPageTitle(__("Your Cart", "com_shop"));
        $params = Factory::getComponent('shop')->getParams();

        $this->loadTemplate("cart");
    }

    public function checkout() {

        Factory::getHead()->setPageTitle(__("Checkout", "com_shop"));

        Factory::getHead()->addscript('jquery');
        Factory::getHead()->addstyle('chosen', Factory::getComponent('shop')->getAssetsUrl() . '/chosen.css');
        Factory::getHead()->addscript('chosen', Factory::getComponent('shop')->getAssetsUrl() . '/js/chosen.jquery.js');
        Factory::getHead()->addScript('jquery-validate', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.validate.js");
        Factory::getHead()->addscript('json', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.json-2.2.js");
        Factory::getHead()->addscript('block', Factory::getComponent('shop')->getAssetsUrl() . "/js/block.js");
        Factory::getHead()->addscript('ckeckout', Factory::getComponent('shop')->getAssetsUrl() . "/js/checkout.js");



        $this->loadTemplate("checkout");
    }

    public function country_states_select() {
        $this->loadTemplate('country_states_select');
    }

    public function update_totals() {


        $cart = Factory::getComponent('shop')->getHelper('cart');


        if ($cart->is_empty()) {
            die("<div>" . __("Your session has expired and the cart is empty!", "com_shop") . "</div>");
        }

        $this->loadTemplate('order');
    }

    public function ccard_form() {
        $input = Factory::getApplication()->getInput();

        $this->assign("input", $input);

        Factory::getHead()->setPageTitle(__("Provide payment details", "com_shop"));
        $this->loadTemplate('ccard_form');
    }

    public function do_payment() {

        $this->gateway->do_payment($this->order_id, $this->order);
    }

    public function order_receipt() {
        Factory::getHead()->setPageTitle(__("Order Receipt", "com_shop"));
        Factory::getHead()->addStyle('receipt_styles', Factory::getComponent('shop')->getAssetsUrl() . "/receipt-view.css");
        
		$this->loadTemplate('order_receipt');
    }

}
