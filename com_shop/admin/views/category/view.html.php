<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewCategory extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Category', 'com_shop'), 'default', 'folder');
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);


        $this->assign('list_templates', $this->getModel('category')->getItemListTemplates());
        $this->assign('product_templates', $this->getModel('category')->getProductTemplates());

        $mainframe = Factory::getMainFrame();


        $this->assign('rootImage', Factory::getApplication('shop')->getAssetsUrl() . "/images/root.png");
        $this->assign('folderImage', Factory::getApplication('shop')->getAssetsUrl() . "/images/folder.png");

        $mainframe->addStyle('jquery-bratbs-css', Factory::getApplication('shop')->getAssetsUrl() . "/btabs.style.css");
        $mainframe->addScript('jquery-btabs-js', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.btabs.js");


        $mainframe->addStyle('jquery-tree-css', Factory::getApplication('shop')->getAssetsUrl() . '/jstree.css');
        $mainframe->addScript('jquery-tree-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.jstree.js');
        $mainframe->addScript('jquery-hotkeys-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.hotkeys.js');
        $mainframe->addScript('jquery-cookie-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.cookie.js');

        wp_enqueue_script('media-upload');
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');

        $mainframe->addCustomHeadTag('category-display', '
            <script type=\'text/javascript\'>
                jQuery(function() {
                    jQuery("dl.tabs").btabs();
					
					setTimeout(function(){
					jQuery("#shop_info").html("").slideUp();
					},4000);
					
                });
            </script>');

        parent::display('category.form');
    }

}