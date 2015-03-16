<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewShipping extends view {

    public function display() {
        $bar = toolbar::getInstance('toolbar', __('Carrier', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=add_carrier'));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('shipping-display', '
           <script type="text/javascript">
            jQuery(function() {
            jQuery("#toggle").click(function(){
                  jQuery("INPUT[type=\'checkbox\']").attr(\'checked\', jQuery(\'#toggle\').is(\':checked\'));   
              });
              });
           </script>
        ');

         $this->loadTemplate('list_carriers');
    }

    public function list_rates() {

        $bar = toolbar::getInstance('toolbar', __('Rates', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping'));
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=add_rate&carrier=' . $this->carrier->pk()));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('shipping-list_rates', '
           <script type="text/javascript">
            jQuery(function() {
            jQuery("#toggle").click(function(){
                  jQuery("INPUT[type=\'checkbox\']").attr(\'checked\', jQuery(\'#toggle\').is(\':checked\'));
              });
              });
           </script>
        ');

         $this->loadTemplate('list_rates');
    }

    public function add_carrier() {

        $bar = toolbar::getInstance('toolbar', __('Carrier', 'com_shop'), 'default', 'cube');

        if ($this->row->pk()) {
            $bar->appendButton('Link', 'list', __('rates', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=list_rates&carrier=' . $this->row->pk()));
        }
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

         $this->loadTemplate('add_carrier_form');
    }

    public function add_rate() {

        $input = Factory::getApplication()->getInput();

        $bar = toolbar::getInstance('toolbar', __('Add Rate', 'com_shop'), 'default', 'cube');
        $carrier = $this->row->carrier | $input->get('cid', null, "INT");
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-shipping&task=list_rates&carrier=' . $carrier));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');


         $this->loadTemplate('add_rate_form');
    }

    public function select_states() {

         $this->loadTemplate('select_states');
    }

    public function standart_shipping_params() {
        $this->loadTemplate('standart_shipping_params');
    }

}
