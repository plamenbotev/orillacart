<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewCategory extends view {

    public function display() {

    }

    public function add_fields() {
        $this->prepare();
        $this->loadTemplate('add_fields');
    }

    public function edit_fields() {
        $this->prepare();
        $this->loadTemplate('edit_fields');
    }

    protected function prepare() {
        $this->assign('list_templates', $this->getModel('category')->getItemListTemplates());

        wp_enqueue_media();
        wp_enqueue_script('shop-category-fields', Factory::getComponent('shop')->getAssetsUrl() . '/js/category-fields.js', array('jquery'), ORILLACART_VERSION, true);
    }

}
