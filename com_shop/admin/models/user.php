<?php

defined('_VALID_EXEC') or die('access denied');

class userModel extends model {

    public function is_user($id) {
        $this->db->setQuery("SELECT count(*) FROM `#_users` WHERE id = " . (int) $id);

        return (int) $this->db->loadResult();
    }

    public function save() {

        $input = Factory::getApplication()->getInput();

        $id = $input->get("id", 0, "INT");

        Factory::getComponent('shop')->getTable('user')->load($id)->bind($input->post)->store();
    }

}
