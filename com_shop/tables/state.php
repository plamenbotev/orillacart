<?php

defined('_VALID_EXEC') or die('access denied');

class stateTable extends table {

    public $state_id = null;
    public $country_id = null;
    public $state_name = '';
    public $state_3_code = null;
    public $state_2_code = null;

    public function __construct() {
        $foreign_keys = array('country_id');
        parent::__construct('state_id', '#_shop_state', $foreign_keys);
    }

    public function load_by_code($country, $state) {

        $this->db->setQuery("SELECT * FROM {$this->table} WHERE country_id = '" . $this->db->secure($country) . "' AND `state_2_code` = '" . $this->db->secure($state) . "' LIMIT 1");

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        if ($result = $this->db->nextObject()) {


            $this->is_empty = false;
            return $this->bind($result);
        } else {
            return $this;
        }
    }

}
