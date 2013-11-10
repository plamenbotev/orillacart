<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerCountry extends controller {

    protected function __default() {

        $model = $this->getModel('country');

        $this->getView('country');


        $res = $model->getCountryList();

        $this->view->assign('res', $res);

        $total = $res->found_rows();
        $pagination = new paginator($total, request::getVar('limitstart', 0, 'METHOD', 'int'), request::getVar('limit', 10, 'METHOD', 'int'));
        $pagination->url = "javascript:void(0);";
        $pagination->onclick = "document.adminForm.task.value='';document.adminForm.limitstart.value=%s;document.adminForm.submit();return false";


        $this->view->assign('pagination', $pagination);

        parent::display();
    }

    protected function state_list() {

        $model = $this->getModel('country');


        $this->getView('country');
        $country_id = Request::getVar('country_id', null, 'REQUEST', 'string');
        $this->view->assign('country_id', $country_id);
        $res = $model->getStateList();
        $total = $res->found_rows();

        $this->view->assign('res', $res);

        $pagination = new paginator($total, request::getVar('limitstart', 0, 'METHOD', 'int'), request::getVar('limit', 10, 'METHOD', 'int'));
        $pagination->url = "javascript:void(0);";
        $pagination->onclick = "document.adminForm.task.value='state_list'; document.adminForm.limitstart.value=%s;document.adminForm.submit();return false";
        $this->view->assign('pagination', $pagination);

        parent::display('state_list');
    }

    protected function add_country() {

        $model = $this->getModel('country');
        $this->getView('country');

        $country_id = request::getVar('country_id', null, 'METHOD', 'string');
        $row = Factory::getApplication('shop')->getTable('country')->load_by_code($country_id);
        $this->view->assign('row', $row);

        parent::display('country_form');
    }

    protected function add_state() {

        $model = $this->getModel('country');
        $this->getView('country');

        $country_id = request::getString('country_id', null);
        $state_id = request::getVar('state_id', null, 'METHOD', 'int');
        $row = Factory::getApplication('shop')->getTable('state')->load($state_id);
        if ($row->pk()) {

            $country_id = $row->country_id;
        }
        outputFilter::objectHTMLSafe($row);
        $this->view->assign('row', $row);
        $this->view->assign('country_id', $country_id);

        parent::display('state_form');
    }

    protected function save_country() {

        $model = $this->getModel('country');

        $this->getView('country');

        $country_id = request::getVar('country_id', null, 'REQUEST');

        try {
            $row = Factory::getApplication('shop')->getTable('country')->load($country_id)->bind(Request::get('POST'))->store();
            Factory::getApplication('shop')->setMessage(__("Saved", 'com_shop'));
        } catch (Exception $e) {
            Factory::getApplication('shop')->setMessage($e->getMessage());
        }

        $this->execute();
    }

    protected function save_state() {

        $model = $this->getModel('country');

        $this->getView('country');

        $state_id = request::getInt('state_id', null, 'POST');

        try {
            $row = Factory::getApplication('shop')->getTable('state')->load($state_id)->bind(Request::get('POST'))->store();
            Factory::getApplication('shop')->setMessage(__("Saved", 'com_shop'));
        } catch (Exception $e) {

            Factory::getApplication('shop')->setMessage($e->getMessage());
        }

        $this->execute('state_list');
    }

    protected function delete() {

        $model = $this->getModel('country');

        $this->getView('country');

        try {
            $model->deleteCountry();

            Factory::getApplication('shop')->setMessage(__("List altered", 'com_shop'));
        } catch (Exception $e) {

            Factory::getApplication('shop')->setMessage($e->getMessage());
        }

        $this->execute();
    }

    protected function delete_state() {

        $model = $this->getModel('country');

        $this->getView('country');

        try {
            $model->deleteState();

            Factory::getApplication('shop')->setMessage(__("List altered", 'com_shop'));
        } catch (Exception $e) {

            Factory::getApplication('shop')->setMessage($e->getMessage());
        }

        $this->execute('state_list');
    }

    protected function ajax_get_states() {

        $level = ob_get_level();
        Request::ajaxMode();
        while ($level) {
            ob_end_clean();
            $level--;
        }

        $cid = Request::getString('country', null);


        $model = $this->getModel('country');

        $countries = $model->getStatesByCountry($cid);

        if (is_null($countries)) {

            header("HTTP/1.0 500 INTERNAL SERVER ERROR");
            header('Content-type: text/html; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");


            echo strings::htmlentities(__("no country selected", 'com_shop'));
            die();
        } else {

            $res = "<select name='shop_state'><option value=''></option>";

            foreach ($countries as $o) {
                $res .=" <option value='" . $o->state_2_code . "'>" . strings::stripAndEncode($o->state_name) . "</option>";
            }
            $res . "</select>";

            header("HTTP/1.0 200 OK");
            header('Content-type: text/html; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");

            echo $res;
            die();
        }
    }

}