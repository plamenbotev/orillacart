<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewUser extends view {

    public function display() {
        
    }

    public function user_form() {
        $this->assign('billing', $this->row->get_billing());
        $this->assign('shipping', $this->row->get_shipping());
         $this->loadTemplate('user_form');
    }

    public function country_states_select() {
         $this->loadTemplate('country_states_select');
    }

}
