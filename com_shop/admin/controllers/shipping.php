<?php

class shopControllerShipping extends controller {

    protected function __default() {

        $model = $this->getModel("shipping");
        $this->getView('shipping');



        $res = $model->list_carriers();

        $pagination = new paginator($res->found_rows(), request::getInt('limitstart', 0), Factory::getApplication('shop')->getParams()->get('objects_per_page'));


        $this->view->assign('pagination', $pagination);
        $this->view->assign('rows', $res);

        parent::display();
    }

    protected function add_carrier() {

        $id = Request::getInt('method_id', null);

        $row = Factory::getApplication('shop')->getTable('carrier')->load($id);
        $model = $this->getModel('shipping');

        $assigned = $model->get_used_classes();
        if ($row->class != 'standart_shipping') {
            $key = array_search($row->class, $assigned);

            if ($key !== false)
                unset($assigned[$key]);
        }
        $shipping_classes = array();
        $shipping_classes = apply_filters('register_shipping_class', $shipping_classes);

        $class_names = array();
        $class_options = '';
        foreach ((array) $shipping_classes as $class) {
            if ($class instanceof standart_shipping && !in_array($class->get_class_name(), $assigned)) {
                $class_names[] = $class->get_class_name();
                if (strtolower($row->class) == strtolower(get_class($class))) {
                    ob_start();
                    $class->print_options($row->pk());
                    $class_options = ob_get_contents();
                    ob_end_clean();
                }
            }
        }


        $this->getView('shipping');
        $this->view->assign('class_names', $class_names);
        $this->view->assign('class_options', $class_options);
        $this->view->assign('row', $row);

        parent::display('add_carrier');
    }

    protected function save() {
        $model = $this->getModel('shipping');
        $this->getView('shipping');
        $assigned = $model->get_used_classes();

        $row = Factory::getApplication('shop')
                ->getTable('carrier')
                ->load(Request::getInt('method_id', null))
                ->bind($_POST, array('params'));


        if ($row->class != 'standart_shipping') {
            $key = array_search($row->class, $assigned);

            if ($key !== false)
                unset($assigned[$key]);
        }

        if (!$row->store()) {

            $this->view->assign('row', $row);

            $shipping_classes = array();
            $shipping_classes = apply_filters('register_shipping_class', $shipping_classes);

            $class_names = array();
            $class_options = '';
            foreach ((array) $shipping_classes as $class) {
                if ($class instanceof standart_shipping && !in_array($class->get_class_name(), $assigned)) {
                    $class_names[] = $class->get_class_name();
                    if (strtolower($row->class) == strtolower(get_class($class))) {
                        ob_start();
                        $class->print_options($row->pk());
                        $class_options = ob_get_contents();
                        ob_end_clean();
                    }
                }
            }

            $this->view->assign('class_names', $class_names);
            $this->view->assign('class_options', $class_options);
            return parent::display('add_carrier');
        } else {

            $shipping_classes = array();
            $shipping_classes = apply_filters('register_shipping_class', $shipping_classes);

            foreach ((array) $shipping_classes as $r) {
                if ($r instanceof standart_shipping) {

                    if (strtolower($row->class) == strtolower(get_class($r))) {

                        $r->save_options($row->pk());
                        break;
                    }
                }
            }


            Factory::getApplication('shop')->setMessage(__('Carrier saved!', 'com_shop'));
            return $this->execute();
        }
    }

    protected function get_class_options() {

        $class = Request::getCmd('class', 'standart_shipping');
        $carrier_id = Request::getInt('carrier_id', null);

        $shipping_classes = array();
        $shipping_classes = apply_filters('register_shipping_class', $shipping_classes);



        foreach ((array) $shipping_classes as $r) {
            if ($r instanceof standart_shipping) {

                if (strtolower($class) == strtolower(get_class($r))) {

                    $r->print_options($carrier_id);
                    break;
                }
            }
        }


        die();
    }

    protected function delete() {

        $ids = Request::getVar('ids', null, 'POST', 'ARRAY');

        if (empty($ids)) {
            Factory::getApplication('shop')->addError(__("Select carriers to be removed!", 'com_shop'));
            return $this->execute();
        }
        $model = $this->getModel('shipping');
        $count = $model->remove_carriers($ids);

        Factory::getApplication('shop')->setMessage(__("Selected carriers were deleted.", 'com_shop'));
        return $this->execute();
    }

    protected function delete_rates() {

        $model = $this->getModel('shipping');
        $this->getView('shipping');
        $carrier = Factory::getApplication('shop')->getTable('carrier')->load(Request::getInt('carrier', null));
        $ids = Request::getVar('ids', array(), 'POST');
        if (!$carrier->pk()) {
            Factory::getApplication('shop')->setMessage(__("Invalid carrier", "com_shop"));
            return $this->execute();
        }

        if (empty($ids)) {
            Factory::getApplication('shop')->addError(__('Nothing selected', 'com_shop'));
            return $this->execute('list_rates');
        }

        $model->remove_rates($ids, $carrier->pk());
        Factory::getApplication('shop')->setMessage(__('Rates deleted', 'com_shop'));
        return $this->execute('list_rates');
    }

    protected function list_rates() {

        $cid = Request::getInt('carrier', null);

        $carrier = Factory::getApplication('shop')->getTable('carrier')->load($cid);
        if ($carrier->pk()) {

            $this->getView('shipping');
            $model = $this->getModel('shipping');


            $res = $model->list_rates($carrier->pk());

            $pagination = new paginator($res->found_rows(), request::getInt('limitstart', 0), Factory::getApplication('shop')->getParams()->get('objects_per_page'));


            $this->view->assign('carrier', $carrier);
            $this->view->assign('pagination', $pagination);
            $this->view->assign('rows', $res);


            return parent::display('list_rates');
        } else {

            Factory::getApplication('shop')->addError(__('No such carrier!', 'com_shop'));
            return $this->execute();
        }
    }

    protected function add_rate() {


        $cid = Request::getInt('carrier', null);
        $id = Request::getInt('id', null);


        $carrier = Factory::getApplication('shop')->getTable('carrier')->load($cid);
        $rate = Factory::getApplication('shop')->getTable('shipping_rate')->load($id);

        if (!$carrier->pk() && !$rate->pk()) {

            Factory::getApplication('shop')->addError(__("Choose carrier!", 'com_shop'));
            return $this->execute();
        } else if (!$rate->pk()) {

            $rate->carrier = $carrier->pk();
        }

        $this->getView('shipping');
        $country_model = $this->getModel('country');

        $res = $country_model->getCountryList(true);

        $this->view->assign("countries", $res->loadObjectList());

        unset($res);

        $states = array();

        if (!empty($rate->shipping_rate_country)) {

            $states = $country_model->getStatesByCountry($rate->shipping_rate_country);
        }

        $vat_model = $this->getModel('tax');

        $this->view->assign('vat_groups', $vat_model->getAllGroups());

        $this->view->assign('states', $states);
        $this->view->assign('row', $rate);


        parent::display('add_rate');
    }

    protected function load_states() {



        $model = $this->getModel('country');
        $rows = (array) $model->getStatesByCountry((array) $_POST['countries']);

        $this->getView('shipping');
        $this->view->assign('rows', $rows);

        parent::display('select_states');
    }

    protected function save_rate() {



        $this->getView('shipping');


        $row = Factory::getApplication('shop')
                ->getTable('shipping_rate')
                ->load(Request::getInt('shipping_rate_id', null))
                ->bind($_POST);

        if (!$row->store()) {




            $country_model = $this->getModel('country');

            $res = $country_model->getCountryList(true);

            $this->view->assign("countries", $res->loadObjectList());

            unset($res);

            $states = array();

            if (!empty($row->shipping_rate_country)) {
                $states = $country_model->getStatesByCountry($row->shipping_rate_country);
            }

            $vat_model = $this->getModel('tax');

            $this->view->assign('vat_groups', $vat_model->getAllGroups());

            $this->view->assign('states', $states);
            $this->view->assign('row', $row);

            return parent::display('add_rate');
        } else {
            Factory::getApplication('shop')->setMessage(__('rate saved!', 'com_shop'));
            return $this->execute('list_rates');
        }
    }

}