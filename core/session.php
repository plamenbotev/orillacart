<?php

class session implements SessionHandlerInterface {

    private $ses_id;
    private $db;
    private $table;
    private $ses_life;
    private $ses_start;

    static public function initialize($table = '#_shop_sessions') {


        static $instance = null;
        if (!$instance)
            $instance = new self($table);
        return $instance;
    }

    private function __construct($table = '#_shop_sessions') {

        $this->db = Factory::getDBO();
        $this->table = $table;
        if (session_id()) {
            session_unset();
            session_destroy();
        }

        // true registers session_write_close() on shutdown
        session_set_save_handler($this, true);
    }

    #[\ReturnTypeWillChange]
    public function open($path, $name) {
        $this->ses_life = ini_get('session.gc_maxlifetime');
        return true;
    }

    #[\ReturnTypeWillChange]
    public function close() {
        $this->gc();
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($ses_id) {

        $session_sql = "SELECT ses_value FROM " . $this->table . " WHERE ses_id = '" . $this->db->secure($ses_id) . "' LIMIT 1";
        $this->db->setQuery($session_sql);


        if (!$this->db->getResource()) {

            return false;
        }

        return (string) $this->db->loadResult();
    }

    #[\ReturnTypeWillChange]
    public function write($ses_id, $data) {



        if (!isset($this->ses_start))
            $this->ses_start = time();
        $session_sql = "SELECT * FROM " . $this->table . " WHERE ses_id='" . $this->db->secure($ses_id) . "'";

        $this->db->setQuery($session_sql);

        if (!$this->db->getResource()) {

            return false;
        }



        if ($this->db->numRows() == 0) {
            $session_sql = "INSERT INTO " . $this->table . " (ses_id, last_access, ses_start, ses_value) VALUES ('" . $this->db->secure($ses_id) . "', " . time() . ", " . $this->ses_start . ", '" . $this->db->secure($data) . "')";
        } else {
            $session_sql = "UPDATE " . $this->table . " SET last_access=" . time() . ", ses_value='" . $this->db->secure($data) . "' WHERE ses_id='" . $this->db->secure($ses_id) . "'";
        }

        $this->db->setQuery($session_sql);
        if (!$this->db->getResource()) {

            return false;
        } else
            return TRUE;
    }

    public function __destruct() {
        
    }

    #[\ReturnTypeWillChange]
    public function destroy($ses_id) {

        $session_sql = "DELETE FROM " . $this->table . " WHERE ses_id = '" . $this->db->secure($ses_id) . "'";
        $this->db->setQuery($session_sql);
        if (!$this->db->getResource()) {

            return false;
        } else
            return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime = null) {
        $ses_life = time() - (int) ($max_lifetime ? $max_lifetime : $this->ses_life);
        $session_sql = "DELETE FROM " . $this->table . " WHERE last_access < $ses_life";
        $this->db->setQuery($session_sql);
        if (!$this->db->getResource()) {

            return false;
        } else
            return true;
    }

}
