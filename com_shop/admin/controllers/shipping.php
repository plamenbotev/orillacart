<?php

class shopControllerShipping extends controller {

    protected function __default() {

        $model = $this->getModel("shipping");
        $this->getView('shipping');

        $input = Factory::getApplication()->getInput();


        $res = $model->list_carriers();

        $pagination = new paginator($res->found_rows(), $input->get('limitstart', 0, "INT"), Factory::getComponent('shop')->getParams()->get('objects_per_page'));


        $this->view->assign('pagination', $pagination);
        $this->view->assign('rows', $res);

        parent::display();
    }

    protected function add_carrier() {

        $input = Factory::getApplication()->getInput();
        $id = $input->get('method_id', null, "INT");

        $row = Factory::getComponent('shop')->getTable('carrier')->load($id);
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

        $input = Factory::getApplication()->getInput();

        $row = Factory::getComponent('shop')
                ->getTable('carrier')
                ->load($input->get('method_id', null, "INT"))
                ->bind($input->post, array('params'));


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


            Factory::getComponent('shop')->setMessage(__('Carrier saved!', 'com_shop'));
            return $this->execute();
        }
    }

    protected function get_class_options() {

        $input = Factory::getApplication()->getInput();

        $class = $input->get('class', 'standart_shipping', "CMD");
        $carrier_id = $input->get('carrier_id', null, "INT");

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

        $input = Factory::getApplication()->getInput();
        $ids = $input->get('ids', array(), 'ARRAY');

        if (empty($ids)) {
            Factory::getComponent('shop')->addError(__("Select carriers to be removed!", 'com_shop'));
            return $this->execute();
        }
        $model = $this->getModel('shipping');
        $count = $model->remove_carriers($ids);

        Factory::getComponent('shop')->setMessage(__("Selected carriers were deleted.", 'com_shop'));
        return $this->execute();
    }

    protected function delete_rates() {

        $model = $this->getModel('shipping');
        $this->getView('shipping');
        $input = Factory::getApplication()->getInput();
        $carrier = Factory::getComponent('shop')->getTable('carrier')->load($input->get('carrier', null, "INT"));
        $ids = $input->get('ids', array(), 'ARRAY');
        if (!$carrier->pk()) {
            Factory::getComponent('shop')->setMessage(__("Invalid carrier", "com_shop"));
            return $this->execute();
        }

        if (empty($ids)) {
            Factory::getComponent('shop')->addError(__('Nothing selected', 'com_shop'));
            return $this->execute('list_rates');
        }

        $model->remove_rates($ids, $carrier->pk());
        Factory::getComponent('shop')->setMessage(__('Rates deleted', 'com_shop'));
        return $this->execute('list_rates');
    }

    protected function list_rates() {

        $input = Factory::getApplication()->getInput();

        $cid = $input->get('carrier', null, "INT");

        $carrier = Factory::getComponent('shop')->getTable('carrier')->load($cid);
        if ($carrier->pk()) {

            $this->getView('shipping');
            $model = $this->getModel('shipping');


            $res = $model->list_rates($carrier->pk());

            $pagination = new paginator($res->found_rows(), $input->get('limitstart', 0, "INT"), Factory::getComponent('shop')->getParams()->get('objects_per_page'));


            $this->view->assign('carrier', $carrier);
            $this->view->assign('pagination', $pagination);
            $this->view->assign('rows', $res);


            return parent::display('list_rates');
        } else {

            Factory::getComponent('shop')->addError(__('No such carrier!', 'com_shop'));
            return $this->execute();
        }
    }

    protected function add_rate() {

        $input = Factory::getApplication()->getInput();

        $cid = $input->get('carrier', null, "INT");
        $id = $input->get('id', null, "INT");


        $carrier = Factory::getComponent('shop')->getTable('carrier')->load($cid);
        $rate = Factory::getComponent('shop')->getTable('shipping_rate')->load($id);

        if (!$carrier->pk() && !$rate->pk()) {

            Factory::getComponent('shop')->addError(__("Choose carrier!", 'com_shop'));
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

        $input = Factory::getApplication()->getInput();

        $row = Factory::getComponent('shop')
                ->getTable('shipping_rate')
                ->load($input->get('shipping_rate_id', null, "INT"))
                ->bind($input->post);

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
            Factory::getComponent('shop')->setMessage(__('rate saved!', 'com_shop'));
            return $this->execute('list_rates');
        }
    }

}
