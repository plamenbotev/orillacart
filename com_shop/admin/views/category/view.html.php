<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewCategory extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Category', 'com_shop'), 'default', 'folder');
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');


        $this->assign('list_templates', $this->getModel('category')->getItemListTemplates());
        $this->assign('product_templates', $this->getModel('category')->getProductTemplates());

        $Head = Factory::getHead();


        $this->assign('rootImage', Factory::getComponent('shop')->getAssetsUrl() . "/images/root.png");
        $this->assign('folderImage', Factory::getComponent('shop')->getAssetsUrl() . "/images/folder.png");

        $Head->addStyle('jquery-bratbs-css', Factory::getComponent('shop')->getAssetsUrl() . "/btabs.style.css");
        $Head->addScript('jquery-btabs-js', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.btabs.js");


        $Head->addStyle('jquery-tree-css', Factory::getComponent('shop')->getAssetsUrl() . '/jstree.css');
        $Head->addScript('jquery-tree-js', Factory::getComponent('shop')->getAssetsUrl() . '/js/jquery.jstree.js');
        $Head->addScript('jquery-hotkeys-js', Factory::getComponent('shop')->getAssetsUrl() . '/js/jquery.hotkeys.js');
        $Head->addScript('jquery-cookie-js', Factory::getComponent('shop')->getAssetsUrl() . '/js/jquery.cookie.js');

        wp_enqueue_script('media-upload');
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');

        $Head->addCustomHeadTag('category-display', '
            <script type=\'text/javascript\'>
                jQuery(function() {
                    jQuery("dl.tabs").btabs();
					
					setTimeout(function(){
					jQuery("#shop_info").html("").slideUp();
					},4000);
					
                });
            </script>');

         $this->loadTemplate('category.form');
    }

}
