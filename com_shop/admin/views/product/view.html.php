<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewProduct extends view {

    public function poduct_meta_js() {
        global $post;
        $mainframe = Factory::getMainFrame();

        $this->assign('rootImage', Factory::getApplication('shop')->getAssetsUrl() . "/images/root.png");
        $this->assign('folderImage', Factory::getApplication('shop')->getAssetsUrl() . "/images/folder.png");


        $mainframe->addScript('jquery');
        /* load tabs */
        $mainframe->addStyle('jquery-btatbs-css', Factory::getApplication('shop')->getAssetsUrl() . "/btabs.style.css");
        $mainframe->addScript('jquery-btabs-js', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.btabs.js");


        $mainframe->addScript('jquery-ui-core');
        $mainframe->addStyle('jquery-ui-css', Factory::getApplication('shop')->getAssetsUrl() . "/jquery.ui.css");

        $mainframe->addScript('jquery-calendar-js', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.ui.datepicker.js");

        /* load overlib */
        Factory::getMainframe()->addScript('tipsy', Factory::getApplication('shop')->getAssetsUrl() . "/js/tipsy.js");
        Factory::getMainframe()->addStyle('tipsy', Factory::getApplication('shop')->getAssetsUrl() . "/tipsy.css");

        /* load the tree */
        $mainframe->addStyle('jquery-tree-css', Factory::getApplication('shop')->getAssetsUrl() . '/jstree.css');
        $mainframe->addScript('jquery-tree-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.jstree.js');
        $mainframe->addScript('jquery-hotkeys-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.hotkeys.js');
        $mainframe->addScript('jquery-cookie-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.cookie.js');

        /* load the ui and the dialog plugin */
        $mainframe->addScript('jquery-ui-dialog');

        wp_enqueue_script('jquery-ui-sortable');

        /* attributes */
        Factory::getMainframe()->addscript('attributesjs', Factory::getApplication('shop')->getAssetsUrl() . "/js/attribs.js", array("jquery-ui-sortable"), false, true);

        Factory::getMainframe()->addscript('product-display', Factory::getApplication('shop')->getAssetsUrl() . "/js/product-display.js");
        

        $mainframe->addCustomHeadTag('product-display', '
            <script type=\'text/javascript\'>
                 jsShopAdminHelper.data.folderImage = "' . $this->folderImage . '";
                 jsShopAdminHelper.data.rootImage = "' . $this->rootImage . '";
                 jsShopAdminHelper.data.pid = "' . $this->row->product->id . '";
            </script>');
    }

    public function product_meta() {

        $this->loadTemplate('form');
    }

    public function category_meta() {
        $this->loadTemplate('tree');
    }

}