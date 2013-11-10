<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewAttributes extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Attributes', 'com_shop'), 'default', 'puzzle');
        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-attributes&task=addnew'), false, true);
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()', false, true);

        Factory::getMainframe()->addCustomHeadTag('attributes-display', "
        <script type='text/javascript'>
         jQuery(document).ready(function() {
           jQuery('#toggle').click(
                function(){
                    jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));
                });
           });
        </script>
        ");

        parent::display('list_attribute_sets');
    }

    public function newsetform() {

        $bar = toolbar::getInstance('toolbar', __('New Attribute Set', 'com_shop'), 'default', 'puzzle');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-attributes'), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);

        Factory::getMainframe()->addScript('jquery-ui-dialog');
        Factory::getMainframe()->addStyle('jquery-ui-dialog', Factory::getApplication('shop')->getAssetsUrl() . "/jquery.ui.css");
        wp_enqueue_script('jquery-ui-sortable');

        /* attributes */
        Factory::getMainframe()->addscript('attributesjs', Factory::getApplication('shop')->getAssetsUrl() . "/js/attribs.js", array("jquery-ui-sortable"), false, true);

        parent::display('newsetform');
    }

    public function edit_set() {

        $bar = toolbar::getInstance('toolbar', __('Edit Attribute Set', 'com_shop'), 'default', 'puzzle');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-attributes'), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);

        $images_path = Factory::getApplication('shop')->getAssetsUrl() . '/images/product_attributes/thumb/';

        /* load the ui and the dialog plugin */
        Factory::getMainframe()->addScript('jquery-ui-dialog');
        Factory::getMainframe()->addStyle('jquery-ui-dialog', Factory::getApplication('shop')->getAssetsUrl() . "/jquery.ui.css");
        wp_enqueue_script('jquery-ui-sortable');

        /* attributes */
        Factory::getMainframe()->addscript('attributesjs', Factory::getApplication('shop')->getAssetsUrl() . "/js/attribs.js", array("jquery-ui-sortable"), false, true);

        $this->assign('images_path', $images_path);

        parent::display('edit_set');
    }

}