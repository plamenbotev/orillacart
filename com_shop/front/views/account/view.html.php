<?php

class shopViewAccount extends View {

    public function display() {
        Factory::getMainframe()->addstyle('account-view', Factory::getApplication('shop')->getAssetsUrl() . "/account-view.css");
        Factory::getMainframe()->addscript('jquery');
        Factory::getMainframe()->setPageTitle(__("Your Account","com_shop"));
        Factory::getMainframe()->addCustomHeadTag('account-coutry-select-ajax', "<script type='text/javascript'>
            jQuery(document).ready(function($) {
                $('select#billing_country,select#shipping_country').live('change',function(){
                    var obj = this.id.substring(0,this.id.indexOf('_country'));
                    $.ajax({
                        type: 'get',
                        url: shop_helper.ajaxurl+'?action=framework-ajax-front&component=shop&con=cart&task=load_states&country='+this.value+'&type='+obj,
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
        parent::display('account');
    }

    public function login_form() {
        Factory::getMainframe()->setPageTitle(__("Your Account","com_shop"));
        parent::display('login_form');
    }

    public function view_order() {
        Factory::getMainframe()->setPageTitle(__("Order Receipt","com_shop"));
        Factory::getMainframe()->addStyle('receipt-styles', Factory::getApplication('shop')->getAssetsUrl() . "/receipt-view.css");
        parent::display('order_receipt');
    }

}