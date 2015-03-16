<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewCountry extends view {

    public function display() {


        $input = Factory::getApplication()->getInput();
        $this->assign("input", $input);

        $bar = toolbar::getInstance('toolbar', __('Countries', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-country&task=add_country'));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('country-display', "
            <script type='text/javascript'>
                jQuery(document).ready(function() {
                    jQuery('#toggle').click(
                        function(){
                          jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
                        }
                    );
                 });
            </script>");

         $this->loadTemplate('country_list');
    }

    public function state_list() {

        $input = Factory::getApplication()->getInput();
        $this->assign("input", $input);

        $bar = toolbar::getInstance('toolbar', __('States list', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url("admin.php?page=component_com_shop-country&task=add_state&country_id=" . $this->country_id));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('country-state_list', "
            <script type='text/javascript'>
                jQuery(document).ready(function() {
                    jQuery('#toggle').click(
                        function(){
                          jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
                        }
                    );
                });
            </script>");

         $this->loadTemplate('state_list');
    }

    public function country_form() {

        $bar = toolbar::getInstance('toolbar', __('Country', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-country'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');
         $this->loadTemplate('country_form');
    }

    public function state_form() {

        $bar = toolbar::getInstance('toolbar', __('State', 'com_shop'), 'globe');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url("admin.php?page=component_com_shop-country&task=state_list&country_id=" . $this->country_id));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');
        $this->loadTemplate('state_form');
    }

}
