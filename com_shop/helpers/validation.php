<?php

class validation {

    protected $db = null;

    public static function getInstance() {

        static $instance = null;

        if ($instance instanceof self)
            return $instance;

        return $instance = new self();
    }

    protected function __construct() {
        $this->db = Factory::getDBO();
    }

    public function is_in_eu($id) {

        $d = (int) $id;

        $this->db->setQuery("SELECT in_eu FROM `#_shop_country` WHERE country_2_code = '" . $this->db->secure($id) . "' LIMIT 1");

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

        $this->db->setQuery("SELECT count(*) FROM #_shop_country WHERE country_2_code = '" . $this->db->secure($id) . "'");

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return (int) $this->db->loadResult();
    }

    public function is_state($id) {

        if (is_numeric($id)) {
            $this->db->setQuery("SELECT count(*) FROM #_shop_state WHERE state_id = " . (int) $id);
        } else if (is_string($id) && strings::strlen($id) == 2) {
            $this->db->setQuery("SELECT count(*) FROM #_shop_state WHERE state_2_code = '" . $this->db->secure($id) . "'");
        } else {
            return false;
        }
        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }
        return (int) $this->db->loadResult();
    }

    public function state_in_country($sid, $cid) {
        $state_id = $this->db->secure($sid);
        $country_id = $this->db->secure($cid);

        if (!$state_id || !$country_id)
            return false;

        $this->db->setQuery("SELECT count(*) FROM `#_shop_state` where state_2_code = '{$state_id}' AND country_id = '{$country_id}' ");

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }
        return (bool) $this->db->loadResult();
    }

    public function country_has_states($id) {
        $id = $this->db->secure($id);
        $this->db->setQuery("SELECT count(*) FROM `#_shop_state` where country_id = '" . $id . "' ");

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        return (int) $this->db->loadResult();
    }

    public function is_email($str) {

        return is_email($str);
    }

    public function is_zipcode($str) {

        if (strlen(trim(preg_replace('/[\s\-A-Za-z0-9]/', '', $str))) > 0)
            return false;

        return true;
    }

    public function is_phone($phone) {
        if (empty($phone))
            return false;
        if (strlen(trim(preg_replace('/[\s\#0-9_\-\+\(\)]/', '', $phone))) > 0)
            return false;
        return true;
    }

}
