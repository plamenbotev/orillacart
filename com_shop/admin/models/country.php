<?php

defined('_VALID_EXEC') or die('access denied');

class countryModel extends model {

    public function is_country($id) {

        if (is_numeric($id)) {

            $this->db->setQuery("SELECT count(*) FROM #_shop_country WHERE country_id = " . (int) $id);
        } else {
            $this->db->setQuery("SELECT count(*) FROM #_shop_country WHERE country_2_code = '" . $this->db->secure($id) . "'");
        }
        return (int) $this->db->loadResult();
    }

    public function getCountryList($all = false) {

        $input = Factory::getApplication()->getInput();

        $start = $input->get('limitstart', 0, "INT");
        $offset = $input->get('limit', 10, "INT");
        $keyword = $this->db->secure($input->get('keyword', '', "WORD"));
        $where = array();
        $where[] = '1';

        if (!empty($keyword) && !$all) {

            $where[] = " AND ( country_name LIKE '{$keyword}%' OR country_3_code LIKE '{$keyword}%' OR country_2_code LIKE '{$keyword}%') ";
        }


        $que = "SELECT SQL_CALC_FOUND_ROWS * FROM `#_shop_country` WHERE " . implode(' ', $where) . " ORDER BY country_name ASC ";

        if (!$all) {

            $que .=" LIMIT {$start},{$offset} ";
        }

        $this->db->setQuery($que);

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function getStatesByCountry($c) {



        $codes = array();


        if (is_array($c)) {



            foreach ((array) $c as $co) {
                $co = trim($co);
                if (empty($co))
                    continue;

                $codes[] = "'" . $this->db->secure($co) . "'";
            }


            $where = '';

            if (!empty($codes)) {
                $where = " OR country_id IN(" . implode(",", $codes) . ") ";
            }



            $this->db->setQuery("SELECT * FROM `#_shop_state` WHERE 0 " . $where . " ORDER BY country_id, state_name ASC");

            if (!$this->db->getResource()) {

                throw new Exception($this->db->getErrorString());
            }
            return (array) $this->db->loadObjectList();
        } else if ($this->is_country($c)) {

            $this->db->setQuery("SELECT * FROM `#_shop_state` WHERE country_id = '{$c}' ORDER BY state_name ASC");


            if (!$this->db->getResource()) {

                throw new Exception($this->db->getErrorString());
            }
            return (array) $this->db->loadObjectList();
        } else
            return null;
    }

    public function getStateList() {

        $input = Factory::getApplication()->getInput();

        $start = $input->get('limitstart', 0, "INT");
        $offset = $input->get('limit', 10, "INT");
        $keyword = $this->db->secure($input->get('keyword', '', "WORD"));
        $country_id = $input->get('country_id', null, "WORD");


        $where = array();
        $where[] = '1';


        if (!$country_id || !$this->is_country($country_id)) {

            throw new Exception(__('A country ID could not be found', 'com_shop'));
        } else {

            $where[] = " AND country_id = '{$country_id}' ";
        }


        if (!empty($keyword)) {

            $where[] = " AND ( state_name LIKE '{$keyword}%' OR state_3_code LIKE '{$keyword}%' OR state_2_code LIKE '{$keyword}%') ";
        }

        $this->db->setQuery("SELECT SQL_CALC_FOUND_ROWS * FROM `#_shop_state` WHERE " . implode(' ', $where) . " ORDER BY state_name ASC LIMIT {$start},{$offset}");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function deleteCountry() {

        $input = Factory::getApplication()->getInput();

        $ids = $input->get('country_id', array(), 'ARRAY');

        $ids = (array) array_map('intval', $ids);
        if (empty($ids)) {

            throw new Exception(__('nothing selected', 'com_shop'));
        }
        $this->db->setQuery("DELETE FROM `#_shop_country` WHERE country_id IN(" . implode(',', $ids) . ")");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return true;
    }

    public function deleteState() {

        $input = Factory::getApplication()->getInput();

        $ids = $input->get('state_id', array(), 'ARRAY');

        $ids = (array) array_map('intval', $ids);
        if (empty($ids)) {

            throw new Exception(__('nothing selected', 'com_shop'));
        }
        $this->db->setQuery("DELETE FROM `#_shop_state` WHERE state_id IN(" . implode(',', $ids) . ")");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return true;
    }

}
