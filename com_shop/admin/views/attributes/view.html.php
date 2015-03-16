<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewAttributes extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Attributes', 'com_shop'), 'default', 'puzzle');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-attributes&task=edit'));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addCustomHeadTag('attributes-display', "
        <script type='text/javascript'>
         jQuery(document).ready(function() {
           jQuery('#toggle').click(
                function(){
                    jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));
                });
           });
        </script>
        ");

         $this->loadTemplate('list_attribute_sets');
    }

    public function newsetform() {

        $bar = toolbar::getInstance('toolbar', __('New Attribute Set', 'com_shop'), 'default', 'puzzle');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-attributes'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

        Factory::getHead()->addScript('jquery-ui-dialog');
        Factory::getHead()->addStyle('jquery-ui-dialog', Factory::getComponent('shop')->getAssetsUrl() . "/jquery.ui.css");
        wp_enqueue_script('jquery-ui-sortable');

        /* attributes */
        Factory::getHead()->addscript('attributesjs', Factory::getComponent('shop')->getAssetsUrl() . "/js/attribs.js", array("jquery-ui-sortable"), false, true);

         $this->loadTemplate('newsetform');
    }

    public function edit_set() {

        $bar = toolbar::getInstance('toolbar', __('Edit Attribute Set', 'com_shop'), 'default', 'puzzle');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-attributes'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');


        /* load the ui and the dialog plugin */
        Factory::getHead()->addScript('jquery-ui-dialog');
        Factory::getHead()->addStyle('jquery-ui-dialog', Factory::getComponent('shop')->getAssetsUrl() . "/jquery.ui.css");
        wp_enqueue_script('jquery-ui-sortable');

        /* attributes */
        Factory::getHead()->addscript('attributesjs', Factory::getComponent('shop')->getAssetsUrl() . "/js/attribs.js", array("jquery-ui-sortable"), false, true);



         $this->loadTemplate('edit_set');
    }

}
