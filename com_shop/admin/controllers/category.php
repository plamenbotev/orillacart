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
        $this->view->assignref('settings', Factory::getApplication('shop')->getParams());
		
        $model = $this->getModel('category');
		$this->view->setModel($model);
        try {
            $res = $model->saveCat($_POST);
        } catch (message $e) {

            Factory::getApplication('shop')->setMessage($e->getMessage());
            parent::display();
            return;
        }
        
        if ($res)
            Factory::getApplication('shop')->setMessage(__("Category saved", 'com_shop'));
        else
            Factory::getApplication('shop')->setMessage(__("Error saving category", 'com_shop'));
        parent::display();
    }

}