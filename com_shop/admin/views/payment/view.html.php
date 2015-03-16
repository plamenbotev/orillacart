<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewPayment extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Payment', 'com_shop'), 'default', 'power-cord');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-payment&task=add_payment'));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('payment-display', '
        <script type="text/javascript">
            jQuery(function() {
                jQuery("#toggle").click(function(){
                  jQuery("INPUT[type=\'checkbox\']").attr(\'checked\', jQuery(\'#toggle\').is(\':checked\'));   
                });
             });
        </script>
        ');

         $this->loadTemplate('list_payment_methods');
    }

    public function add_payment() {

        $bar = toolbar::getInstance('toolbar', __('payment', 'com_shop'), 'default', 'power-cord');

        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-payment'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

         $this->loadTemplate('add_payment_form');
    }

}
