<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewProduct extends view {

    public function poduct_meta_js() {
        global $post;
        $Head = Factory::getHead();

        $Head->addScript('jquery');
        /* load tabs */
        $Head->addStyle('jquery-btatbs-css', Factory::getComponent('shop')->getAssetsUrl() . "/btabs.style.css");
        $Head->addScript('jquery-btabs-js', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.btabs.js");
        $Head->addScript('jquery-blockui-js', Factory::getComponent('shop')->getAssetsUrl() . "/js/block.js");

        $Head->addScript('jquery-ui-core');
        $Head->addStyle('jquery-ui-css', Factory::getComponent('shop')->getAssetsUrl() . "/jquery.ui.css");

        $Head->addScript('jquery-calendar-js', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.ui.datepicker.js");

        /* load overlib */
        Factory::getHead()->addScript('tipsy', Factory::getComponent('shop')->getAssetsUrl() . "/js/tipsy.js");
        Factory::getHead()->addStyle('tipsy', Factory::getComponent('shop')->getAssetsUrl() . "/tipsy.css");

        /* load the ui and the dialog plugin */
        $Head->addScript('jquery-ui-dialog');

        wp_enqueue_script('jquery-ui-sortable');

        /* attributes */
        Factory::getHead()->addscript('attributesjs', Factory::getComponent('shop')->getAssetsUrl() . "/js/attribs.js", array("jquery-ui-sortable"), false, true);

        Factory::getHead()->addscript('product-display', Factory::getComponent('shop')->getAssetsUrl() . "/js/product-display.js");


        $Head->addCustomHeadTag('product-display', '
            <script type=\'text/javascript\'>
                 jsShopAdminHelper.data.pid = "' . $this->row->product->id . '";
            </script>');
    }

    public function product_meta() {

        $this->loadTemplate('form');
    }

}
