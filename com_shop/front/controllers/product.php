<?php

class shopControllerProduct extends controller {

    protected function __default() {
        $this->getView('product');
        $model = $this->getModel('product');
        $this->view->setModel($model);

        parent::display();
    }

    protected function get_price() {
        $this->getView('product');
        $model = $this->getModel('product');
        $this->view->setModel($model);

        parent::display('load_child_product');
    }

}
