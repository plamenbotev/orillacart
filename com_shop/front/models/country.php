<?php

class countryModel extends model {

    public function is_in_eu($id) {

        if (is_numeric($id)) {
            $id = (int) $id;
            $this->db->setQuery("SELECT in_eu FROM `#_shop_country` WHERE country_id = {$id} LIMIT 1");
        } else {
            switch (strings::strlen($id)) {
                case 3:
                    $this->db->setQuery("SELECT in_eu FROM `#_shop_country` WHERE country_3_code = '" . $this->db->secure($id) . "' LIMIT 1");
                    break;
                case 2:
                default:
                    $this->db->setQuery("SELECT in_eu FROM `#_shop_country` WHERE country_2_code = '" . $this->db->secure($id) . "' LIMIT 1");
                    break;
            }
        }
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        switch ($this->db->loadResult()) {
            case 'yes': return true;
                break;
            case 'no': default: return false;
                break;
        }
    }

    public function is_country($id) {

        if (is_numeric($id)) {
            $this->db->setQuery("SELECT count(*) FROM #_shop_country WHERE country_id = " . (int) $id);
        } else {
            $this->db->setQuery("SELECT count(*) FROM #_shop_country WHERE country_2_code = '" . $this->db->secure($id) . "'");
        }
        return (int) $this->db->loadResult();
    }

    public function is_state($id) {
        if (is_numeric($id)) {
            $this->db->setQuery("SELECT count(*) FROM #_shop_state WHERE state_id = " . (int) $id);
        } else {
            $this->db->setQuery("SELECT count(*) FROM #_shop_state WHERE state_2_code = " . $this->db->secure($id));
        }
        return (int) $this->db->loadResult();
    }

    public function getCountryList() {

        $allowed_countries = (array) Factory::getComponent('shop')->getPArams()->get('retail_countries');

        $ids = array_map(array($this->db, 'secure'), $allowed_countries);
        $where = '';
        if (empty($ids)) {
            $where = "1";
        } else {
            $where = "country_2_code IN(" . implode(',', $ids) . ")";
        }

        $que = "SELECT  * FROM `#_shop_country` WHERE " . $where . " ORDER BY country_name ASC ";

        $this->db->setQuery($que);

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        return $this->db->loadObjectList();
    }

    public function getStatesByCountry($c) {

        if (is_array($c)) {
            foreach ($c as $k => $v) {
                $c[$k] = "'" . $this->db->seure($v) . "'";
            }

            $this->db->setQuery("SELECT * FROM `#_shop_state` WHERE country_id IN(" . implode(',', $c) . ") ORDER BY country_id, state_name ASC");

            if (!$this->db->getResource()) {
                throw new Exception($this->db->getErrorString());
            }
            return (array) $this->db->loadObjectList();
        } else if ($this->is_country($c)) {

            $this->db->setQuery("SELECT * FROM `#_shop_state` WHERE country_id = '" . $this->db->secure($c) . "' ORDER BY state_name ASC");

            if (!$this->db->getResource()) {
                throw new Exception($this->db->getErrorString());
            }
            return (array) $this->db->loadObjectList();
        } else
            return null;
    }

}
