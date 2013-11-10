<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerAttributes extends controller {

    protected function __default() {

        $this->getView('attributes');

        $model = $this->getModel('attributes');

        $this->view->assign('att_sets', $model->getAllAttributeSets());

        parent::display();
    }

    protected function changestate() {

        $model = $this->getModel('attributes');

        $id = (int) $_POST['id'];

        $ret = new stdClass();

        $ret->status = 1;
        $ret->row = $model->changeState($id);

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($ret);
        die();
    }

    protected function delete_sets() {

        $sets = (array) array_map('intval', (array) $_POST['ids']);

        $model = $this->getModel('attributes');
        $this->getView('attributes');

        $db = Factory::getDBO();
        $db->startTransaction();
        $db->setQuery("SELECT attribute_id FROM `#_shop_attribute` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");

        if ($db->numRows() > 0) {

            try {

                $model->delete((array) $db->loadArray());
            } catch (Exception $e) {
                $db->rollback();
                Factory::getApplication('shop')->setMessage(__("error deleteing", 'com_shop'));
                $this->execute();

                return false;
            }


            $db->setQuery("DELETE FROM  `#_shop_attribute` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");
            if (!$db->getResource()) {

                $db->rollback();
                Factory::getApplication('shop')->setMessage(__("error deleteing", 'com_shop'));
                $this->execute();
                return false;
            }
        }


        $db->setQuery("DELETE FROM `#_shop_attribute_set` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");

        if (!$db->getResource()) {
            $db->rollback();
            Factory::getApplication('shop')->setMessage(__("error deleteing", 'com_shop'));
            $this->execute();
            return false;
        }

        $db->commit();

        Factory::getApplication('shop')->setMessage(__("success", 'com_shop'));

        $this->execute();
        return true;
    }

    protected function save() {

        $model = $this->getModel('attributes');
        $this->getView('attributes');

        try {
            $model->store();

            Factory::getApplication('shop')->setMessage(__("Set saved", 'com_shop'));
        } catch (Exception $e) {
            Factory::getApplication('shop')->setMessage($e->getMessage());
        }

        $this->execute();
    }

    protected function edit() {

        $stock_model = $this->getModel('stockroom');

        $set_id = (int) $_GET['id'];

        $model = $this->getModel('attributes');
        $this->getView('attributes');

        $this->view->assign('stock_rooms', $stock_model->getAllStockRooms());

        if ($model->is_set($set_id)) {
            $this->view->assign('set', $model->getattributes($set_id));

            parent::display('edit_set');
            return;
        } else {
            $this->addnew();
            return;
        }
    }

    protected function addnew() {

        $this->getView('attributes');

        parent::display('newsetform');

        return true;
    }

    protected function save_stock() {

        $model = $this->getModel('attributes');

        $el_id = (int) $_POST['id'];
        $el_type = (string) $_GET['object'];
        $values = (array) array_map('intval', (array) $_POST['values']);


        $result = new stdClass();
        $result->status = null;
        $result->msg = null;


        try {
            $result->status = $model->updateStockAndDeleteDiff($el_id,$values);

            $result->status = true;
            $result->msg = __("Stockroom saved", 'com_shop');
        } catch (Exception $e) {

            $result->status = false;
            $result->msg = $e->getMessage();
        }

        ob_end_clean();


        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($result);
        die();
    }

    protected function get_stock() {

        $type = (string) $_GET['type'];
        $id = (int) $_GET['id'];

        $result = array();

        $model = $this->getModel('attributes');

        try {
            $result = $model->getStocks($id);
        } catch (Exception $e) {
            header("HTTP/1.0 500 INTERNAL SERVER ERROR");
            header('Content-type: text/json; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");

            echo strings::htmlentities($e->getMessage());
            die();
        }

        ob_end_clean();

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($result);
        die();
    }

}