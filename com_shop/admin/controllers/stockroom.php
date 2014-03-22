<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerStockroom extends controller {

    protected function __default() {

        $this->getView('stockroom');
        $model = $this->getModel('stockroom');

        $opts = new stdClass();

        if (isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0) {

            $opts->limit = (int) $_GET['limit'];
        } else {

            $opts->limit = 20;
        }

        if (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) {

            $opts->start = (int) $_GET['page'] * $opts->limit;
        }

        $this->view->assignref('settings', Factory::getApplication('shop')->getParams());
        $this->view->assignref('db', clone $model->listStockRooms($opts));


        parent::display();
    }

    protected function addnew($row = null) {

        $this->getView('stockroom');
        $model = $this->getModel('stockroom');
        $country_model = $this->getModel('country');

        $row = Factory::getApplication('shop')->getTable('stockroom')->load(Request::getInt('id', null));

        $tax_model = $this->getModel('tax');

        $this->view->assign('tax_groups', (array) $tax_model->getAllGroups());

        if ($row->country_code) {
            $states = $country_model->getStatesByCountry($row->country_code);
        }

        $all_countries = $country_model->getCountryList(true);


        $this->view->assign('countries', $all_countries->loadObjectList());
        $this->view->assign('states', $states);

        $this->view->assign('row', $row);

        $this->view->assignref('settings', Factory::getApplication('shop')->getParams());

        parent::display('addnew');
    }

    protected function delete() {


        $ids = null;


        if (isset($_REQUEST['ids'])) {

            if (is_array($_REQUEST['ids'])) {

                $ids = array();

                foreach ($_REQUEST['ids'] as $v) {

                    $ids[] = (int) $v;
                }
            } else {

                $ids = (int) $_REQUEST['ids'];
            }



            $this->getView('stockroom');

            $model = $this->getModel('stockroom');

            $res = $model->delete($ids);

            if ($res) {

                Factory::getApplication('shop')->setMessage(__("Success", 'com_shop'));
                $this->execute();
                return;
            } else {


                Factory::getApplication('shop')->setMessage(__("error deleteing", 'com_shop'));
                $this->execute();
                return;
            }
        } else {


            Factory::getApplication('shop')->setMessage(__("Nothing selected", 'com_shop'));
            $this->execute();
            return;
        }
    }

    protected function save() {

        $row = null;
        try {
           
            if (isset($_POST['id']) && is_numeric($_POST['id']) && !empty($_POST['id'])) {
                $row = Factory::getApplication('shop')->getTable('stockroom')->load((int) $_POST['id'])->bind($_POST)->store();
            } else {
				if(isset($_POST['id'])){
					unset($_POST['id']);
				}
                $row = Factory::getApplication('shop')->getTable('stockroom')->bind($_POST)->store();
            }

            Factory::getApplication('shop')->setMessage(__('Stockroom saved', 'com_shop'));
        } catch (Exception $e) {

            Factory::getApplication('shop')->setMessage($e->getMessage());

            $this->execute('addnew', $row);
            return;
        }

        $this->execute();
        return;
    }

    protected function changestate() {

        $model = $this->getModel('stockroom');

        $id = (int) $_POST['id'];

        $model->changeState($id);

        $ret = new stdClass();

        $ret->status = 1;
        $ret->row = $model->loadStockRoom($id);


        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($ret);
        die();
    }

    protected function manage() {


        $model = $this->getModel('stockroom');
        $this->getView('stockroom');

        if (request::getVar('action', null, 'POST', 'string') == 'save') {
            try {

                $model->updateStocks();
            } catch (Exception $e) {

                Factory::getApplication('shop')->setMessage($e->getMessage());
            }

            Factory::getApplication('shop')->setMessage(__("Amounts updated", 'com_shop'));
        }


        $_SESSION['filter']['cats'] = array();

        $filter = request::getVar('filter', array(), 'POST', 'array');

        $_SESSION['filter']['cats'] = $filter['cats'];


        //reset the pagination if the filter is used
        if (!$_POST['action'])
            unset($_POST['limitstart']);



        $this->view->assign('stockrooms', $model->getAllStockRooms());

        $res = $model->getObjects();
        $this->view->assign('objects', $res);

        $total = (int) $res->found_rows();

        $pagination = new paginator($total, request::getInt('limitstart', 0), request::getInt('limit', 10));
        $pagination->url = "javascript:void(0);";
        $pagination->onclick = "document.adminForm.action.value='paginate';document.adminForm.limitstart.value=%s;document.adminForm.submit();return false";

        $this->view->assign('pagination', $pagination);


        parent::display('manage_stocks');
    }

}