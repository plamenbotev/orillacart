<?php

defined('_VALID_EXEC') or die('access denied');

class countryTable extends table {

    public $country_id = null;
    public $country_name = null;
    public $country_3_code = null;
    public $country_2_code = null;
    public $in_eu = 'no';

    public function __construct() {

        parent::__construct('country_id', '#_shop_country');
    }

    public function load_by_code($c) {

        $num = 2;
        if (strings::strlen($c) == 3) {
            $num = 3;
        }

        $this->db->setQuery("SELECT * FROM {$this->table} WHERE `country_{$num}_code` = '" . $this->db->secure($c) . "' LIMIT 1");

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
