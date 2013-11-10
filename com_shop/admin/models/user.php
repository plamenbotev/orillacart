<?php

defined('_VALID_EXEC') or die('access denied');

class userModel extends model {

    public function is_user($id) {
        $this->db->setQuery("SELECT count(*) FROM `#_users` WHERE id = " . (int) $id);

        return (int) $this->db->loadResult();
    }

    public function save() {
        $id = Request::getInt('id', null);

        Factory::getApplication('shop')->getTable('user')->load($id)->bind($_POST)->store();
    }

}