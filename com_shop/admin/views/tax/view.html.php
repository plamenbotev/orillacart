<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewTax extends view {

    public function display() {
        $bar = toolbar::getInstance('toolbar', __('Tax groups', 'com_shop'), 'tax');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-tax&task=add_group'));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');


        Factory::getHead()->addCustomHeadTag('tax-display', "
        <script type='text/javascript'>
            jQuery(document).ready(function() {
                jQuery('#toggle').click(function(){
                    jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
                });
            });
        </script>
        ");
		
         $this->loadTemplate('groups_list');
    }

    public function rates_list() {

        $bar = toolbar::getInstance('toolbar', __('Rates list', 'com_shop'), 'tax');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url("admin.php?page=component_com_shop-tax&task=add_rate&tax_group_id=" . $this->tax_group_id));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('tax-rates_list', "
        <script type='text/javascript'>
            jQuery(document).ready(function() {
                jQuery('#toggle').click(function(){
                    jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
                });
            });
        </script>
        ");
         $this->loadTemplate('rates_list');
    }

    public function group_form() {

        $bar = toolbar::getInstance('toolbar', __('Tax group', 'com_shop'), 'tax');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-tax'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

         $this->loadTemplate('group_form');
    }

    public function rate_form() {

        $bar = toolbar::getInstance('toolbar', __('Rate', 'com_shop'), 'tax');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url("admin.php?page=component_com_shop-tax&task=rates_list&tax_group_id=" . $this->tax_group_id), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

         $this->loadTemplate('rate_form');
    }

}
