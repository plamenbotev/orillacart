<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewGeneralsettings extends view {

    public function display() {

        $bar = toolbar::getInstance('toolbar', __('Settings', 'com_shop'), 'default', 'power-cord');
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);

        $mainframe = Factory::getMainFrame();

        $uri = uri::getInstance();

        $this->assign('currenturl', $uri->toString(array('scheme', 'host', 'port', 'path', 'query')));

        $mainframe->setPageTitle(__("Shop Settings", "com_shop"));

        $mainframe->addStyle('jquery-bratbs-css', Factory::getApplication('shop')->getAssetsUrl() . "/btabs.style.css");
        $mainframe->addScript('jquery-btabs-js', Factory::getApplication('shop')->getAssetsUrl() . "/js/jquery.btabs.js");

        $mainframe->addCustomHeadTag('generalsettings-display', '
        <script type=\'text/javascript\'>
            jQuery(function() {
                jQuery("dl.tabs").btabs();
            });
        </script>
        ');

        parent::display('settings');
    }

}