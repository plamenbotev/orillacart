<?php

defined('_VALID_EXEC') or die('access denied');

class shippingModel extends model {

    public function get_used_classes() {

        $this->db->setQuery("SELECT DISTINCT class FROM #_shop_methods WHERE type='shipping' AND class IS NOT NULL");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        return (array) $this->db->loadArray();
    }

    public function list_carriers() {


        $start = request::getInt('limitstart', 0);
        $limit = (int) Factory::getApplication('shop')->getParams()->get('objects_per_page');




        $this->db->setQuery("SELECT SQL_CALC_FOUND_ROWS * FROM `#_shop_methods` WHERE type='shipping' ORDER BY method_order ASC, method_id DESC LIMIT {$start},{$limit}");


        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function list_rates($cid) {

        $start = request::getInt('limitstart', 0);
        $limit = (int) Factory::getApplication('shop')->getParams()->get('objects_per_page');




        $this->db->setQuery("SELECT SQL_CALC_FOUND_ROWS * FROM `#_shop_shipping_rate` WHERE carrier = {$cid} ORDER BY shipping_rate_priority ASC LIMIT {$start},{$limit}");


        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function remove_carriers($ids) {


        $ids = (array) array_map('intval', (array) $ids);
        if (empty($ids))
            return 0;

        $this->db->setQuery("DELETE FROM `#_shop_methods` WHERE type = 'shipping' AND method_id IN(" . implode(',', $ids) . ") LIMIT " . count($ids));
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        return (int) $this->db->affectedRows();
    }

    public function remove_rates($ids, $carrier = null) {

        $ids = (array) array_map('intval', (array) $ids);
        if ($carrier && is_int($carrier)) {
            $this->db->setQuery("DELETE FROM `#_shop_shipping_rate` WHERE carrier = " . (int) $carrier . " AND shipping_rate_id IN(" . implode(',', $ids) . ") LIMIT " . count($ids));
        } else {
            $this->db->setQuery("DELETE FROM `#_shop_shipping_rate` WHERE shipping_rate_id IN(" . implode(',', $ids) . ") LIMIT " . count($ids));
        }
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }
    }

    public function getAllShippingGroups() {

        $groups = get_terms('shipping_group', 'hide_empty=0');

        if (is_wp_error($groups)) {
            throw new Exception($brands->get_error_message());
        }

        return $groups;
    }

}