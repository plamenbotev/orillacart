<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewGeneralsettings extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Settings', 'com_shop'), 'default', 'power-cord');
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

        $Head = Factory::getHead();

        $uri = uri::getInstance();

        $this->assign('currenturl', $uri->toString(array('scheme', 'host', 'port', 'path', 'query')));

        $Head->setPageTitle(__("Shop Settings", "com_shop"));

        $Head->addStyle('jquery-bratbs-css', Factory::getComponent('shop')->getAssetsUrl() . "/btabs.style.css");
        $Head->addScript('jquery-btabs-js', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.btabs.js");

        $Head->addCustomHeadTag('generalsettings-display', '
        <script type=\'text/javascript\'>
            jQuery(function() {
                jQuery("dl.tabs").btabs();
            });
        </script>
        ');

         $this->loadTemplate('settings');
    }

}
