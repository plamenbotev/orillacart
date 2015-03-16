<?php

class shopViewAccount extends View {

    public function display() {
        Factory::getHead()->addstyle('account-view', Factory::getComponent('shop')->getAssetsUrl() . "/account-view.css");
        Factory::getHead()->addscript('jquery');
        Factory::getHead()->setPageTitle(__("Your Account", "com_shop"));
        Factory::getHead()->addCustomHeadTag('account-coutry-select-ajax', "<script type='text/javascript'>
            jQuery(document).ready(function($) {
                $('select#billing_country,select#shipping_country').live('change',function(){
                    var obj = this.id.substring(0,this.id.indexOf('_country'));
                    $.ajax({
                        type: 'get',
                        url: shop_helper.ajaxurl+'?action=ajax-call-front&component=shop&con=cart&task=load_states&country='+this.value+'&type='+obj,
                        success: function (data, text) {
                            document.getElementById(obj+'_states_container').innerHTML = data;

                        },
                        error: function (request, status, error) {
                            throw(request.responseText);

                        }
                    });
                });
           });
</script>");

         $this->loadTemplate('account');
    }

    public function login_form() {
        Factory::getHead()->setPageTitle(__("Your Account", "com_shop"));
        $this->loadTemplate('login_form');
    }

    public function view_order() {
        Factory::getHead()->setPageTitle(__("Order Receipt", "com_shop"));
        Factory::getHead()->addStyle('receipt-styles', Factory::getComponent('shop')->getAssetsUrl() . "/receipt-view.css");
        $this->loadTemplate('order_receipt');
    }

}
