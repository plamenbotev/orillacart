<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewCountry extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Countries', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-country&task=add_country'), false, true);
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()', false, true);

        Factory::getMainframe()->addCustomHeadTag('country-display', "
            <script type='text/javascript'>
                jQuery(document).ready(function() {
                    jQuery('#toggle').click(
                        function(){
                          jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
                        }
                    );
                 });
            </script>");

        parent::display('country_list');
    }

    public function state_list() {

        $bar = toolbar::getInstance('toolbar', __('States list', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url("admin.php?page=component_com_shop-country&task=add_state&country_id=" . $this->country_id), false, true);
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()', false, true);

        Factory::getMainframe()->addCustomHeadTag('country-state_list', "
            <script type='text/javascript'>
                jQuery(document).ready(function() {
                    jQuery('#toggle').click(
                        function(){
                          jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
                        }
                    );
                });
            </script>");

        parent::display('state_list');
    }

    public function country_form() {

        $bar = toolbar::getInstance('toolbar', __('Country', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-country'), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);
        parent::display('country_form');
    }

    public function state_form() {

        $bar = toolbar::getInstance('toolbar', __('State', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url("admin.php?page=component_com_shop-country&task=state_list&country_id=" . $this->country_id), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);
        parent::display('state_form');
    }

}