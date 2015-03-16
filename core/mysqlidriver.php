<?php

defined('_VALID_EXEC') or die('access denied');

class mysqliDriver extends database {

    
    public function __construct() {

        global $wpdb;


        $this->conn = $wpdb->dbh;
        $this->prefix = $wpdb->prefix;
    }

    public function getTableColumns($table, $typeOnly = true) {
        $result = array();

        // Set the query to get the table fields statement.
        $this->setQuery('SHOW FULL COLUMNS FROM ' . $table);
        $fields = $this->loadObjectList();

        if (!$this->getResource()) {
            throw new Exception($this->getErrorString());
        }

        // If we only want the type as the value add just that to the list.
        if ($typeOnly) {
            foreach ($fields as $field) {
                $result[$field->Field] = preg_replace("/[(0-9)]/", '', $field->Type);
            }
        }
        // If we want the whole field data object add that to the list.
        else {
            foreach ($fields as $field) {
                $result[$field->Field] = $field;
            }
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
        mysqli_free_result($this->res);
        $this->res = null;
    }

    public function setquery($que) {

        $que = str_replace('#_', $this->getPrefix(), $que);

        $this->res = mysqli_query($this->conn, $que);
    }

    public function parseQuery($que) {
        return $que = str_replace('#_', $this->getPrefix(), $que);
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
            //$s = 'SET ';

            foreach ($keys as $k => $v) {

                if (is_null($values[$k])) {

                    $s[] = " " . $v . "= NULL ";
                } else {

                    $s[] = " " . $v . "='" . $values[$k] . "'";
                }
            }

            $s = implode(',', $s);



            return 'SET ' . $s;
        }
    }

    public function found_rows() {

        $res = mysqli_query($this->conn, 'SELECT FOUND_ROWS()');

        if (!$res)
            return false;

        return mysqli_result($res, 0);
    }

    public function lastid() {

//$this->setquery("SELECT last_insert_id()");
//if($this->getErrorNo()) return false;
        return mysqli_insert_id($this->conn);
//return $this->loadresult();
    }

    public function getErrorString() {

        return mysqli_error($this->conn);
    }

    public function getErrorNo() {

        return mysqli_errno($this->conn);
    }

    public function getResource() {


        return $this->res;
    }

    public function secure($data) {

        $data = mysqli_real_escape_string($this->conn, $data);
        //$data=addcslashes($data,"%_");
        return $data;
    }

    public function loadassoc() {
        $oblist = array();
        $ob = NULL;
        if ($this->numRows() == 1) {

            return mysqli_fetch_assoc($this->res);
        }
        while ($ob = mysqli_fetch_assoc($this->res)) {
            $oblist[] = $ob;
        }

        return $oblist;
    }

    public function loadarray() {
        $oblist = array();
        $ob = NULL;

        while ($ob = mysqli_fetch_array($this->res, MYSQL_NUM)) {

            $oblist[] = $ob[0];
        }

        return $oblist;
    }

    public function loadColumnArray($col) {
        $oblist = array();
        $ob = NULL;
        mysql_data_seek($this->res, 0);
        while ($ob = mysqli_fetch_assoc($this->res)) {
            $oblist[] = $ob[$col];
        }

        return $oblist;
    }

    public function numRows() {

        return mysqli_num_rows($this->res);
    }

    public function affectedRows() {

        return mysqli_affected_rows($this->conn);
    }

    public function loadobjectlist($col = null) {


        $oblist = array();
        $ob = NULL;

        if (!$col) {
            while ($ob = mysqli_fetch_object($this->res)) {
                $oblist[] = $ob;
            }
        } else {
            while ($ob = mysqli_fetch_object($this->res)) {

                if (!isset($oblist[$ob->$col]) || !is_array($oblist[$ob->$col]))
                    $oblist[$ob->$col] = array();

                $oblist[$ob->$col][] = $ob;
            }
        }

        return $oblist;
    }

    public function nextObject($res = null) {

        if (!is_object($this->res) && !is_object($res))
            return false;

        if (!($this->res instanceof mysqli_result) && !($res instanceof mysqli_result))
            return false;

        if (is_object($res) && $res instanceof mysqli_result)
            return mysqli_fetch_object($res);

        return mysqli_fetch_object($this->res);
    }

    public function loadResult($col = NULL) {

        if ($col) {


            return (is_object($this->res) && $this->res instanceof mysqli_result ) ? mysqli_result($this->res, 0, $col) : false;
        } else if ($this->numRows() > 0) {

            return (is_object($this->res) && $this->res instanceof mysqli_result ) ? mysqli_result($this->res, 0) : false;
        }

        return false;
    }

}

function mysqli_result($res, $row = 0, $col = 0) {

    if (mysqli_num_rows($res) && $row <= (mysqli_num_rows($res) - 1) && $row >= 0) {
        mysqli_data_seek($res, $row);
        $resrow = mysqli_fetch_row($res);
        if (isset($resrow[$col])) {
            return $resrow[$col];
        }
    }
    return false;
}
