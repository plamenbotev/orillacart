<?php

defined('_VALID_EXEC') or die('access denied');

class taxModel extends model {

    public function is_tax_group($id) {

        $this->db->setQuery("SELECT count(*) FROM `#_shop_tax_group` WHERE tax_group_id = " . (int) $id);

        return (int) $this->db->loadResult();
    }

    public function is_tax_rate($id) {

        $this->db->setQuery("SELECT count(*) FROM `#_shop_tax_rate` WHERE tax_rate_id = " . (int) $id);

        return (int) $this->db->loadResult();
    }

    public function getRatesList() {

        $input = Factory::getApplication()->getInput();

        $this->db->setQuery("SELECT a.*,b.country_name,c.state_name FROM `#_shop_tax_rate` AS a 
   
	LEFT JOIN `#_shop_country` AS b ON a.tax_country = b.country_2_code
	LEFT JOIN `#_shop_state` AS c ON a.tax_state = c.state_2_code AND a.tax_country = c.country_id
   
        WHERE a.tax_group_id = " . (int) $input->get('tax_group_id', 0, "INT") . " ORDER BY b.country_name ASC,c.state_name ASC ");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function getGroupsList() {

        $input = Factory::getApplication()->getInput();

        $start = $input->get('limitstart', 0, "INT");
        $offset = $input->get('limit', 10, "INT");
        $keyword = $this->db->secure($input->get('keyword', '', "WORD"));
        $where = array();
        $where[] = '1';

        if (!empty($keyword)) {

            $where[] = " AND  tax_group_name LIKE '{$keyword}%' ";
        }

        $this->db->setQuery("SELECT SQL_CALC_FOUND_ROWS * FROM `#_shop_tax_group` WHERE " . implode(' ', $where) . " ORDER BY tax_group_name ASC LIMIT {$start},{$offset}");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function getRateList() {



        $where = array();
        $where[] = '1';


        if (!$country_id || !$this->is_country($country_id)) {

            throw new Exception(__('A country ID could not be found', 'com_shop'));
        } else {

            $where[] = " AND country_id = '" . $this->db->secure($country_id) . "'";
        }


        if (!empty($keyword)) {

            $where[] = " AND ( state_name LIKE '{$keyword}%' OR state_3_code LIKE '{$keyword}%' OR state_2_code LIKE '{$keyword}%') ";
        }

        $this->db->setQuery("SELECT * FROM `#_shop_state` WHERE " . implode(' ', $where) . " ORDER BY state_name ASC");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function deleteGroup() {

        $input = Factory::getApplication()->getInput();

        $ids = $input->get('tax_group_ids', array(), 'ARRAY');

        $ids = (array) array_map('intval', $ids);
        if (empty($ids)) {

            throw new Exception(__('nothing selected', 'com_shop'));
        }
        $this->db->setQuery("DELETE FROM `#_shop_tax_group` WHERE tax_group_id IN(" . implode(',', $ids) . ")");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return true;
    }

    public function getAllGroups() {

        $this->db->setQuery("SELECT * FROM `#_shop_tax_group` WHERE published = 1 ORDER BY tax_group_name ASC ");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return (array) $this->db->loadObjectList();
    }

    public function deleteRate() {

        $input = Factory::getApplication()->getInput();

        $ids = $input->get('tax_rate_ids', array(), "ARRAY");

        $ids = (array) array_map('intval', $ids);
        if (empty($ids)) {

            throw new Exception(__('nothing selected', 'com_shop'));
        }
        $this->db->setQuery("DELETE FROM `#_shop_tax_rate` WHERE tax_rate_id IN(" . implode(',', $ids) . ")");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return true;
    }

}
