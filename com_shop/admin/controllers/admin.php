<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerAdmin extends controller {

    protected function __default() {
        $this->execute('configuration');
    }

    protected function configuration() {

        $model = $this->getModel('settings');

        $tax_model = $this->getModel('tax');
        $country_model = $this->getModel('country');

        $config = Factory::getComponent('shop')->getParams();

        $this->getView('generalsettings');



        $countries = $country_model->getCountryList(true)->loadObjectList();

        $this->view->assign('countries', $countries);

        $states = array();

        if ($config->get('shop_country')) {

            $states = $country_model->getStatesByCountry($config->get('shop_country'));
        }




        $this->view->assign('states', $states);

        $this->view->assign('tax_groups', (array) $tax_model->getAllGroups());


        $this->view->assignref('settings', $config);


        parent::display();
    }

    protected function saveopts() {

        $model = $this->getModel('settings');

        $this->getView('generalsettings');

        $input = Factory::getApplication()->getInput();

        $params = Factory::getComponent('shop')->getParams();

        try {



            $params->bind($input->post);
        } catch (Exception $e) {
            Factory::getComponent('shop')->setMessage($e->getMessage());
            $this->execute('configuration');
            return;
        }

        try {
            $status = $params->save();
        } catch (Exception $e) {
            Factory::getComponent('shop')->setMessage($e->getMessage());

            $this->execute('configuration');
            return;
        }

        Factory::getComponent('shop')->setMessage(__('settings updated!', 'com_shop'));
        $this->execute('configuration');
        return;
    }

}

?>