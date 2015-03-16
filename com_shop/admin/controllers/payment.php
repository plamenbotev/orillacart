<?php

class shopControllerPayment extends controller {

    protected function __default() {

        $model = $this->getModel("payment");
        $this->getView('payment');



        $res = $model->list_methods();

        $this->view->assign('rows', $res);

        parent::display();
    }

    protected function add_payment() {

        $input = Factory::getApplication()->getInput();

        $id = $input->get('method_id', null, "INT");

        $row = Factory::getComponent('shop')->getTable('payment')->load($id);

        $model = $this->getModel('payment');

        $assigned = $model->get_used_classes();
        if ($row->class != 'standart_shipping') {
            $key = array_search($row->class, $assigned);

            if ($key !== false)
                unset($assigned[$key]);
        }

        $payment_classes = array();
        $payment_classes = apply_filters('register_payment_class', $payment_classes);

        $class_names = array();
        $class_options = '';
        foreach ((array) $payment_classes as $class) {
            if ($class instanceof payment_method && !in_array($class->get_class_name(), $assigned)) {
                $class_names[] = $class->get_class_name();
                if (strtolower($row->class) == strtolower(get_class($class))) {
                    ob_start();
                    $class->print_options($row->pk());
                    $class_options = ob_get_contents();
                    ob_end_clean();
                }
            }
        }

        if (!count($payment_classes)) {
            Factory::getComponent('shop')->addError(__("Please install payment plugin!", "com_shop"));
            return $this->execute();
        }
        $this->getView('payment');
        $this->view->assign('class_names', $class_names);
        $this->view->assign('class_options', $class_options);
        $this->view->assign('row', $row);


        $country = $this->getModel('country');
        $res = $country->getCountryList(true);

        $this->view->assign("countries", $res->loadObjectList());

        parent::display('add_payment');
    }

    protected function save() {

        $this->getView('payment');

        $input = Factory::getApplication()->getInput();

        $row = Factory::getComponent('shop')
                ->getTable('payment')
                ->load($input->get('method_id', null, "INT"))
                ->bind($input->post, array('params'));


        $model = $this->getModel('payment');

        $assigned = $model->get_used_classes();
        if ($row->class != 'standart_shipping') {
            $key = array_search($row->class, $assigned);

            if ($key !== false)
                unset($assigned[$key]);
        }

        if (!$row->store()) {

            $this->view->assign('row', $row);

            $payment_classes = array();
            $payment_classes = apply_filters('register_payment_class', $payment_classes);

            $class_names = array();
            $class_options = '';
            foreach ((array) $payment_classes as $class) {
                if ($class instanceof payment_method && !in_array($class->get_class_name(), $assigned)) {
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
            return parent::display('add_payment');
        } else {

            $payment_classes = array();
            $payment_classes = apply_filters('register_payment_class', $payment_classes);

            foreach ((array) $payment_classes as $r) {
                if ($r instanceof payment_method) {

                    if (strtolower($row->class) == strtolower(get_class($r))) {

                        $r->save_options($row->pk());
                        break;
                    }
                }
            }


            Factory::getComponent('shop')->setMessage(__('Payment saved!', 'com_shop'));
            return $this->execute();
        }
    }

    protected function get_class_options() {

        $input = Factory::getApplication()->getInput();

        $class = $input->get('class', 'payment_method', "CMD");


        $payment_classes = array();
        $payment_classes = apply_filters('register_payment_class', $payment_classes);



        foreach ((array) $payment_classes as $r) {
            if ($r instanceof payment_method) {

                if (strtolower($class) == strtolower(get_class($r))) {

                    $r->print_options();
                    break;
                }
            }
        }


        die();
    }

    protected function delete() {

        $input = Factory::getApplication()->getInput();

        $ids = $input->get("ids", array(), "ARRAY");

        if (empty($ids)) {
            Factory::getComponent('shop')->addError(__("Select methods to be removed!", 'com_shop'));
            return $this->execute();
        }
        $model = $this->getModel('payment');
        $count = $model->remove_methods($ids);

        Factory::getComponent('shop')->setMessage(__("Selected methods were deleted.", 'com_shop'));
        return $this->execute();
    }

}
