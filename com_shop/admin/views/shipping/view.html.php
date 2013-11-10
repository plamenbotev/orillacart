<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewShipping extends view {

    public function display() {
        $bar = toolbar::getInstance('toolbar', __('Carrier', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=add_carrier'), false, true);
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()', false, true);

        Factory::getMainframe()->addCustomHeadTag('shipping-display', '
           <script type="text/javascript">
            jQuery(function() {
            jQuery("#toggle").click(function(){
                  jQuery("INPUT[type=\'checkbox\']").attr(\'checked\', jQuery(\'#toggle\').is(\':checked\'));   
              });
              });
           </script>
        ');

        parent::display('list_carriers');
    }

    public function list_rates() {

        $bar = toolbar::getInstance('toolbar', __('Rates', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping'), false, true);
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=add_rate&carrier=' . $this->carrier->pk()), false, true);
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()', false, true);

        Factory::getMainframe()->addCustomHeadTag('shipping-list_rates', '
           <script type="text/javascript">
            jQuery(function() {
            jQuery("#toggle").click(function(){
                  jQuery("INPUT[type=\'checkbox\']").attr(\'checked\', jQuery(\'#toggle\').is(\':checked\'));
              });
              });
           </script>
        ');

        parent::display('list_rates');
    }

    public function add_carrier() {

        $bar = toolbar::getInstance('toolbar', __('Carrier', 'com_shop'), 'default', 'cube');

        if ($this->row->pk()) {
            $bar->appendButton('Link', 'list', __('rates', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=list_rates&carrier=' . $this->row->pk()), false, true);
        }
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping'), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);

        parent::display('add_carrier_form');
    }

    public function add_rate() {


        $bar = toolbar::getInstance('toolbar', __('Add Rate', 'com_shop'), 'default', 'cube');
        $carrier = $this->row->carrier | Request::getInt('cid', null);
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=list_rates&carrier=' . $carrier), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);


        parent::display('add_rate_form');
    }

    public function select_states() {

        parent::display('select_states');
    }

    public function standart_shipping_params() {
        $this->loadTemplate('standart_shipping_params');
    }

}