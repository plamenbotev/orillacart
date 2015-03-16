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

        $this->view->assign('settings', Factory::getComponent('shop')->getParams());
        $this->view->assign('db', clone $model->listStockRooms($opts));


        parent::display();
    }

    protected function addnew() {

        $this->getView('stockroom');
        $model = $this->getModel('stockroom');
        $country_model = $this->getModel('country');

        $input = Factory::getApplication()->getInput();

        $row = Factory::getComponent('shop')->getTable('stockroom')->load($input->get('id', null, "INT"));

		//the form is loaded from the save task, because the save was not successful.
		//That is why we should prefill the form with the provided details from the $_POST.
		if($input->get("task",null,"CMD") == "save"){
			$row->bind($input->post);
		}
      
        $this->view->assign('row', $row);

        $this->view->assign('settings', Factory::getComponent('shop')->getParams());

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

                Factory::getComponent('shop')->setMessage(__("Success", 'com_shop'));
                $this->execute();
                return;
            } else {


                Factory::getComponent('shop')->setMessage(__("error deleteing", 'com_shop'));
                $this->execute();
                return;
            }
        } else {


            Factory::getComponent('shop')->setMessage(__("Nothing selected", 'com_shop'));
            $this->execute();
            return;
        }
    }

    protected function save() {

        $row = null;

        $input = Factory::getApplication()->getInput();

        try {

            if (isset($_POST['id']) && is_numeric($_POST['id']) && !empty($_POST['id'])) {
                $row = Factory::getComponent('shop')->getTable('stockroom')->load($input->get('id', 0, "INT"))->bind($input->post)->store();
            } else {
                if (isset($_POST['id'])) {
                    unset($_POST['id']);
                }
                $row = Factory::getComponent('shop')->getTable('stockroom')->bind($input->post)->store();
            }

            Factory::getComponent('shop')->setMessage(__('Stockroom saved', 'com_shop'));
        } catch (Exception $e) {

            Factory::getComponent('shop')->setMessage($e->getMessage());

            $this->execute('addnew');
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

        $input = Factory::getApplication()->getInput();

        if ($input->get('action', null, 'CMD') == 'save') {
            try {

                $model->updateStocks();
            } catch (Exception $e) {

                Factory::getComponent('shop')->setMessage($e->getMessage());
            }

            Factory::getComponent('shop')->setMessage(__("Amounts updated", 'com_shop'));
        }


        $_SESSION['filter']['cats'] = array();

        $filter = $input->get('filter', array(), 'ARRAY');

        $_SESSION['filter']['cats'] = isset($filter['cats'])?$filter['cats']:array();


        //reset the pagination if the filter is used
        if (!$input->get("action", null, "CMD"))
            unset($_POST['limitstart']);



        $this->view->assign('stockrooms', $model->getAllStockRooms());

        $res = $model->getObjects();
        $this->view->assign('objects', $res);

        $total = (int) $res->found_rows();

        $pagination = new paginator($total, $input->get('limitstart', 0, "INT"), $input->get('limit', 10, "INT"));
        $pagination->url = "javascript:void(0);";
        $pagination->onclick = "document.adminForm.action.value='paginate';document.adminForm.limitstart.value=%s;document.adminForm.submit();return false";

        $this->view->assign('pagination', $pagination);


        parent::display('manage_stocks');
    }

}
