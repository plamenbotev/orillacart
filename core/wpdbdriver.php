<?php

defined('_VALID_EXEC') or die('access denied');

/**
 * Database driver on top of $wpdb, so the queries share its connection handling,
 * error reporting, query filters and debugging tools.
 *
 * Each instance keeps the rows of its last query; a clone keeps its own copy and cursor.
 */
class wpdbDriver extends database {

    protected $rows = array();
    protected $cursor = 0;
    protected $error = '';
    protected $affected = 0;
    protected $insert_id = 0;

    public function __construct() {

        global $wpdb;

        $this->conn = $wpdb;
        $this->prefix = $wpdb->prefix;
    }

    public function getTableColumns($table, $typeOnly = true) {
        $result = array();

        // Set the query to get the table fields statement.
        $this->setQuery('SHOW FULL COLUMNS FROM ' . $table);

        if (!$this->getResource()) {
            throw new Exception($this->getErrorString());
        }

        foreach ($this->loadObjectList() as $field) {
            // If we only want the type as the value add just that to the list.
            $result[$field->Field] = $typeOnly ? preg_replace("/[(0-9)]/", '', $field->Type) : $field;
        }

        return $result;
    }

    public function startTransaction() {
        $this->setQuery("start transaction");
    }

    public function rollback() {
        $this->setQuery("rollback");
    }

    public function commit() {
        $this->setQuery("commit");
    }

    public function reset() {
        $this->res = null;
        $this->rows = array();
        $this->cursor = 0;
    }

    public function setquery($que) {

        $que = $this->parseQuery($que);

        $result = $this->conn->query($que);

        $this->error = (string) $this->conn->last_error;
        $this->rows = is_array($this->conn->last_result) ? $this->conn->last_result : array();
        $this->cursor = 0;
        $this->affected = (int) $this->conn->rows_affected;
        $this->insert_id = (int) $this->conn->insert_id;

        // a failed query reads as false, a successful one as true even when no rows matched
        $this->res = ($result !== false && $this->error === '');

        if (!$this->res) {
            $this->rows = array();
        }
    }

    public function parseQuery($que) {
        return str_replace('#_', $this->getPrefix(), $que);
    }

    public function buildQuery($arr, $update = false) {

        $keys = array_keys($arr);
        $values = array_values($arr);

        foreach ($keys as $k => $v) {
            $keys[$k] = '`' . $v . '`';
        }

        if (!$update) {

            foreach ($values as $k => $v) {

                if (is_null($v)) {
                    $values[$k] = "NULL";
                } else {
                    $values[$k] = "'" . $v . "'";
                }
            }

            return '(' . implode(',', $keys) . ') VALUES(' . implode(',', $values) . ')';
        } else {
            $s = array();

            foreach ($keys as $k => $v) {

                if (is_null($values[$k])) {
                    $s[] = " " . $v . "= NULL ";
                } else {
                    $s[] = " " . $v . "='" . $values[$k] . "'";
                }
            }

            return 'SET ' . implode(',', $s);
        }
    }

    public function found_rows() {

        $found = $this->conn->get_var('SELECT FOUND_ROWS()');

        return $found === null ? false : $found;
    }

    public function lastid() {
        return $this->insert_id;
    }

    public function getErrorString() {
        return $this->error;
    }

    public function getErrorNo() {
        return $this->error === '' ? 0 : 1;
    }

    public function getResource() {
        return $this->res;
    }

    public function secure($data) {
        // _real_escape marks % with a placeholder that only prepare() removes
        return $this->conn->remove_placeholder_escape($this->conn->_real_escape((string) $data));
    }

    public function loadassoc() {

        if (count($this->rows) == 1) {
            return (array) $this->rows[0];
        }

        return array_map(function ($row) {
            return (array) $row;
        }, $this->rows);
    }

    public function loadarray() {

        $list = array();

        foreach ($this->rows as $row) {
            $values = array_values((array) $row);
            $list[] = $values[0];
        }

        return $list;
    }

    public function loadColumnArray($col) {

        $list = array();

        foreach ($this->rows as $row) {
            $list[] = $row->$col;
        }

        return $list;
    }

    public function numRows() {
        return count($this->rows);
    }

    public function affectedRows() {
        return $this->affected;
    }

    public function loadobjectlist($col = null) {

        if (!$col) {
            return $this->rows;
        }

        $list = array();

        foreach ($this->rows as $row) {
            $list[$row->$col][] = $row;
        }

        return $list;
    }

    public function nextObject() {

        // false without a result, null past the last row, as the mysqli driver did
        if (!$this->res) {
            return false;
        }
        if (!isset($this->rows[$this->cursor])) {
            return null;
        }

        return $this->rows[$this->cursor++];
    }

    /**
     * First row's value of the given column (name or position), or of the first column.
     */
    public function loadResult($col = NULL) {

        if (empty($this->rows)) {
            return false;
        }

        $row = (array) $this->rows[0];

        // like the mysqli driver, a NULL value reads as false
        if (is_string($col) && $col !== '') {
            return isset($row[$col]) ? $row[$col] : false;
        }

        $values = array_values($row);
        $col = (int) $col;

        return isset($values[$col]) ? $values[$col] : false;
    }

}
