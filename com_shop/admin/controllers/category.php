<?php

defined('_VALID_EXEC') or die('access denied');

/**
 * Extra fields for the product_cat taxonomy on the standard WP term screens.
 * Only reachable as internal requests from the term form hooks.
 */
class shopControllerCategory extends controller {

    protected function __default() {
        return;
    }

    protected function add_fields() {
        if (!Request::is_internal())
            return;

        $this->getView('category');
        $this->view->setModel($this->getModel('category'));
        $this->view->assign('row', $this->getModel('category')->loadMeta(0));

        parent::display('add_fields');
    }

    protected function edit_fields() {
        if (!Request::is_internal())
            return;

        $input = Factory::getApplication()->getInput();

        $this->getView('category');
        $this->view->setModel($this->getModel('category'));
        $this->view->assign('row', $this->getModel('category')->loadMeta($input->get('id', 0, "INT")));

        parent::display('edit_fields');
    }

    protected function save() {
        if (!Request::is_internal())
            return;

        $input = Factory::getApplication()->getInput();

        $this->getModel('category')->saveMeta($input->get('id', 0, "INT"), $input->post);
    }

}
