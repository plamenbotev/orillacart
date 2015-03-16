<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerTax extends controller {

    protected function __default() {

        $model = $this->getModel('tax');

        $this->getView('tax');

        $res = $model->getGroupsList();

        $this->view->assign('res', $res);

        $input = Factory::getApplication()->getInput();

        $total = $res->found_rows();
        $pagination = new paginator($total, $input->get('limitstart', 0, 'INT'), $input->get('limit', 10, 'INT'));
        $pagination->url = "javascript:void(0);";
        $pagination->onclick = "document.adminForm.task.value='';document.adminForm.limitstart.value=%s;document.adminForm.submit();return false";


        $this->view->assign('pagination', $pagination);

        parent::display();
    }

    protected function rates_list() {

        $model = $this->getModel('tax');

        $input = Factory::getApplication()->getInput();

        $this->getView('tax');
        $tax_group_id = $input->get('tax_group_id', null, 'INT');
        $this->view->assign('tax_group_id', $tax_group_id);
        $res = $model->getRatesList();

        $this->view->assign('res', $res);



        parent::display('rates_list');
    }

    protected function add_group() {



        $model = $this->getModel('tax');
        $this->getView('tax');

        $input = Factory::getApplication()->getInput();

        $tax_group_id = $input->get('tax_group_id', null, 'INT');
        $row = Factory::getComponent('shop')->getTable('tax_group')->load($tax_group_id);
        $this->view->assign('row', $row);


        parent::display('group_form');
    }

    protected function add_rate() {


        $model = $this->getModel('tax');
        $this->getView('tax');
        $country_model = $this->getModel('country');
        $input = Factory::getApplication()->getInput();

        $tax_rate_id = $input->get('tax_rate_id', null, "INT");
        $states = array();



        if ($tax_rate_id && $model->is_tax_rate($tax_rate_id)) {


            $row = Factory::getComponent('shop')->getTable('tax_rate')->load($tax_rate_id);
            $this->view->assign('row', $row);
            $this->view->assign('tax_group_id', $row->tax_group_id);



            if ($row->tax_country) {

                $states = $country_model->getStatesByCountry($row->tax_country);
            }
        } else {

            $tax_group_id = $input->get('tax_group_id', null, "INT");

            if (!$tax_group_id || !$model->is_tax_group($tax_group_id)) {

                Factory::getComponent('shop')->setMessage(__("No tax group selected", 'com_shop'));
                $this->execute();
                return 0;
            } else {

                $this->view->assign('tax_group_id', $tax_group_id);
            }
        }


        $all_countries = $country_model->getCountryList(true);

        $this->view->assign('countries', $all_countries->loadObjectList());
        $this->view->assign('states', $states);
        unset($states);
        unset($all_countries);








        parent::display('rate_form');
    }

    protected function save_group() {


        $model = $this->getModel('tax');

        $this->getView('tax');

        $input = Factory::getApplication()->getInput();

        $group_id = $input->get('tax_group_id', null, 'INT');

        try {
            $row = Factory::getComponent('shop')->getTable('tax_group')->load($group_id)->bind($input->post)->store();
            Factory::getComponent('shop')->setMessage(__("Saved", 'com_shop'));
        } catch (Exception $e) {
            Factory::getComponent('shop')->setMessage($e->getMessage());
        }

        $this->execute();
    }

    protected function save_rate() {




        $model = $this->getModel('tax');

        $input = Factory::getApplication()->getInput();

        $this->getView('tax');


        try {
            $row = Factory::getComponent('shop')->getTable('tax_rate')->load($input->get('tax_rate_id', null, "INT"))->bind($input->post);
            $row->tax_state = $input->get("shop_state", "", "WORD");

            $row->store();
            Factory::getComponent('shop')->setMessage(__("Saved", 'com_shop'));
        } catch (Exception $e) {
            Factory::getComponent('shop')->setMessage($e->getMessage());
        }

        $this->execute();
    }

    protected function delete_group() {

        $model = $this->getModel('tax');

        $this->getView('tax');

        try {
            $model->deleteGroup();

            Factory::getComponent('shop')->setMessage(__("List altered", 'com_shop'));
        } catch (Exception $e) {

            Factory::getComponent('shop')->setMessage($e->getMessage());
        }

        $this->execute();
    }

    protected function delete_rate() {

        $model = $this->getModel('tax');

        $this->getView('tax');

        try {
            $model->deleteRate();

            Factory::getComponent('shop')->setMessage(__("List altered", 'com_shop'));
        } catch (Exception $e) {

            Factory::getComponent('shop')->setMessage($e->getMessage());
        }

        $this->execute('rate_list');
    }

}
