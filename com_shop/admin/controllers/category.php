<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerCategory extends controller {

    protected function __default() {

        $this->getView('category');
        $this->view->setModel($this->getModel('category'));
        parent::display();
    }

    protected function save() {

        lib::import('image');

        $this->getView('category');
        $this->view->assign('settings', Factory::getComponent('shop')->getParams());

        $model = $this->getModel('category');
        $this->view->setModel($model);
        try {
            $res = $model->saveCat(Factory::getApplication()->getInput()->post);
        } catch (message $e) {

            Factory::getComponent('shop')->setMessage($e->getMessage());
            parent::display();
            return;
        }

        if ($res)
            Factory::getComponent('shop')->setMessage(__("Category saved", 'com_shop'));
        else
            Factory::getComponent('shop')->setMessage(__("Error saving category", 'com_shop'));
        parent::display();
    }

}
