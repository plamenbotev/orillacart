<?php

abstract class table extends BObject {

    const safe_insert = true;

    protected $fk = array();
    protected $is_empty = true;
    protected $table = '';
    protected $pk = '';
    protected $db = null;

    public function pk() {

        return $this->{$this->pk};
    }

    public static function addIncludePath($prefix, $path = '') {
        static $paths;

        if (!isset($paths)) {
            $paths = array();
        }


        if (!isset($paths[$prefix])) {
            $paths[$prefix] = array();
        }

        if (!empty($path)) {

            if (!in_array($path, $paths[$prefix])) {
                array_unshift($paths[$prefix], Path::clean($path));
            }
        }

        return $paths[$prefix];
    }

    public static function getInstance($type, $prefix) {
        $type = preg_replace('/[^A-Z0-9_\.-]/i', '', $type);
        $tableClass = strtolower($type) . "Table";

        if (!class_exists($tableClass)) {

            $path = Path::find(Table::addIncludePath($prefix), $type . ".php");

            if ($path) {
                require_once $path;

                if (!class_exists($tableClass)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $table = new $tableClass();

        if (method_exists($table, 'init'))
            $table->init();



        return $table;
    }

    public function toJson($type = 'array') {
        if ($type == 'array') {
            return json_encode($this->toArray());
        } else {
            return json_encode($this);
        }
    }

    public function toString($type = 'array') {
        if ($type == 'array') {
            return serialize($this->toArray());
        } else {
            return serialize($this);
        }
    }

    public function toArray() {
        return (array) arrayHelper::fromObject($this, true);
    }

    public function __construct($pk, $table, array $fk = array()) {

        if (!$pk || !$table)
            trigger_error("missing pk and/or table name!", E_USER_ERROR);

        $this->db = Factory::getDBO();
        $this->fk = $fk;
        $this->pk = $pk;
        $this->table = $table;
    }

    public function bind($from, $exclude = array()) {

        if (method_exists($this, 'filter')) {
            $from = $this->filter($from);
        }

        if (!is_array($from) && !is_object($from)) {
            throw new Exception(get_class($this) . '::bind failed. Invalid from argument');
        }

        $public_vars = array();

        $public_vars = $this->getPublicFields();


        foreach ($from as $k => $v) {

            if (in_array($k, (array) $exclude)) {

                continue;
            }

            if (array_key_exists($k, $public_vars)) {
                if (in_array($k, $this->fk) && !$v) {

                    $this->$k = null;
                } else
                    $this->$k = $v;
            }
        }
        return $this;
    }

    public function check() {
        return true;
    }

    public function reset() {

        $columns = (array) array_keys($this->getPublicFields());

        $fields = (array) $this->getFields();

        foreach ((array) $fields as $k => $v) {
            if (in_array($k, $columns)) {
                $this->{$k} = $v->Default;
            }
        }


        return $this;
    }

    public function store($safe_insert = false) {


        $k = $this->pk;

        $public_vars = array();
        $public_vars = $this->getPublicFields();

        $arr = array();

        foreach ($public_vars as $key => $v) {


            if (!is_null($v)) {
                $v = $this->db->secure($v);
            }

            //  if($key == $k && !$safe_insert && ) continue;

            $arr[$key] = $v;
        }


        if (!empty($this->$k) && !$safe_insert && !$this->is_empty) {

            $que = "UPDATE " . $this->table . " " . $this->db->buildQuery($arr, true) . " WHERE " . $k . " = '" . (int) $this->$k . "'";

            $this->db->setQuery($que);

            if (!$this->db->getResource()) {
                throw new Exception($this->db->getErrorString());
            }
            return $this->bind($this->load($this->$k));
        } else {

            if ($safe_insert) {

                $que = "REPLACE INTO " . $this->table . " " . $this->db->buildQuery($arr) . " ";
            } else {
                $que = "INSERT INTO " . $this->table . " " . $this->db->buildQuery($arr);
            }

            $this->db->setQuery($que);

            if (!$this->db->getResource()) {

                throw new Exception($this->db->getErrorString());
            }

            $id = null;
            $id = $this->db->lastId();

            if (!$id && $safe_insert)
                $id = $this->$k;

            return $this->bind($this->load($id));
        }
        return false;
    }

    public function getFields() {
        static $cache = null;

        if ($cache === null) {
            // Lookup the fields for this table only once.
            $name = $this->table;
            $fields = $this->db->getTableColumns($name, false);

            if (empty($fields)) {
                throw new Exception('empty table');
                return false;
            }
            $cache = $fields;
        }

        return $cache;
    }

    protected function getPublicFields() {

        $getPFields = create_function('$obj', 'return get_object_vars($obj);');

        return $getPFields($this);
    }

    public function delete() {

        $db = Factory::getDBO();

        if (is_numeric($this->pk())) {
            $this->db->setQuery("DELETE FROM {$this->table} WHERE {$this->pk} = " . (int) $this->pk() . " LIMIT 1");

            if (!$this->db->getResource()) {
                throw new Exception($this->db->getErrorString());
            }
        } else {

            return true;
        }
    }

    public function load($oid = null) {

        $k = $this->pk;

        $kv = null;

        $db = Factory::getDBO();

        if ($oid !== null) {
            $kv = $this->db->secure($oid);
        } else {

            $kv = $this->db->secure($this->$k);
        }

        if (empty($kv))
            return $this;

        $this->db->setQuery("SELECT * FROM {$this->table} WHERE `{$k}` = '{$kv}' LIMIT 1");

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

    public function toCSV($separator = ',') {
        $csv = array();

        foreach (get_object_vars($this) as $k => $v) {
            if (is_array($v) or is_object($v) or $v === null) {
                continue;
            }

            if ($k[0] == '_') {
                // Internal field
                continue;
            }

            $csv[] = '"' . str_replace('"', '""', $v) . '"';
        }

        $csv = implode($separator, $csv);

        return $csv;
    }

    public function getData() {
        $ret = array();

        foreach (get_object_vars($this) as $k => $v) {
            if (($k[0] == '_') || ($k[0] == '*')) {
                // Internal field
                continue;
            }

            $ret[$k] = $v;
        }

        return $ret;
    }

    public function getCSVHeader($separator = ',') {
        $csv = array();

        foreach (get_object_vars($this) as $k => $v) {
            if (is_array($v) or is_object($v) or $v === null) {
                continue;
            }

            if ($k[0] == '_') {
                // Internal field
                continue;
            }

            $csv[] = '"' . str_replace('"', '\"', $k) . '"';
        }

        $csv = implode($separator, $csv);

        return $csv;
    }

    public function set($property, $value = null) {
        $previous = isset($this->$property) ? $this->$property : null;
        $this->$property = $value;
        return $this;
    }

    public function setProperties($properties) {
        if (is_array($properties) || is_object($properties)) {
            foreach ((array) $properties as $k => $v) {
                // Use the set function which might be overridden.
                $this->set($k, $v);
            }
            return $this;
        }

        return $this;
    }

}
