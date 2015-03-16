<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerUser extends controller {

    protected function __default() {

        if (!Request::is_internal())
            return;

        $model = $this->getModel('user');

        $this->getView('user');

        $input = Factory::getApplication()->getInput();

        $col = $input->get("col", null, "STRING");
        $id = $input->get("id", null, "INT");
        switch ($col) {

            case "billing_address":

                $row = Factory::getComponent('shop')->getTable('user')->load($id);
                $customer = Helper::getInstance('customer', 'shop');

                echo $customer->format_billing($row->toArray());



                break;

            case "shipping_address":

                $row = Factory::getComponent('shop')->getTable('user')->load($id);
                $customer = Helper::getInstance('customer', 'shop');

                echo $customer->format_shipping($row->toArray());



                break;
            default:
                global $wpdb;
                $count = $wpdb->get_var("SELECT COUNT(*) 
			FROM $wpdb->posts 
			LEFT JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id
			WHERE meta_value = $id 
			AND meta_key = '_customer_id'
			AND post_type IN ('shop_order') 
			AND post_status = 'publish'");

                echo '<a href="' . admin_url('edit.php?post_status=all&post_type=shop_order&_customer_id=' . $id . '') . '">' . $count . '</a>';
                break;
        }
        return;



        //parent::display();
    }

    protected function user_form() {
        if (!Request::is_internal())
            return;

        $input = Factory::getApplication()->getInput();

        $this->getView('user');

        $row = Factory::getComponent('shop')->getTable('user')->load($input->get('id', null, "INT"));

        $this->view->assign('row', $row);

        parent::display('user_form');
    }

    protected function save() {
        if (!Request::is_internal())
            return;
        $this->getModel('user')->save();
    }

    protected function country_states() {


        $input = Factory::getApplication()->getInput();

        $id = $input->get('country', null, "WORD");
        $type = strtolower($input->get('type', 'billing', "WORD"));

        if (!in_array($type, array('billing', 'shipping'))) {
            $type = 'billing';
        }


        echo field::_('state', $type . '_state')->set_country($id)->render();
        exit;
    }

}
