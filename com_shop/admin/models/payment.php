<?php

defined('_VALID_EXEC') or die('access denied');

class paymentModel extends model {

    public function get_used_classes() {

        $this->db->setQuery("SELECT DISTINCT class FROM #_shop_methods WHERE type='payment' AND class IS NOT NULL");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        return (array) $this->db->loadArray();
    }

    public function list_methods() {



        $this->db->setQuery("SELECT SQL_CALC_FOUND_ROWS * FROM `#_shop_methods` WHERE type='payment' ORDER BY method_order ASC, method_id DESC");


        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function remove_methods($ids) {


        $ids = (array) array_map('intval', (array) $ids);
        if (empty($ids))
            return 0;

        $this->db->setQuery("DELETE FROM `#_shop_methods` WHERE type = 'payment' AND method_id IN(" . implode(',', $ids) . ") LIMIT " . count($ids));
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        return (int) $this->db->affectedRows();
    }

}
