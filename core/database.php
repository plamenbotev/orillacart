<?php

defined('_VALID_EXEC') or die('access denied');

abstract class database {

    protected $conn = null;
    protected $res = null;
    protected $prefix = null;

    public function getPrefix() {
        return $this->prefix;
    }

    public function startTransaction() {
        throw new Exception("the driver dont support or dont implement transactions!");
    }

    public function rollback() {
        throw new Exception("the driver dont support or dont implement transactions!");
    }

    public function commit() {
        throw new Exception("the driver dont support or dont implement transactions!");
    }

    abstract public function getTableColumns($table, $typeOnly = true);
 

    abstract protected function __construct(); //connet to the database

    abstract public function setquery($que); // setquery

    abstract public function getErrorString(); //if there is error return the error string

    abstract public function getErrorNo(); //get error number

    abstract public function getResource(); //get the current resource

    abstract public function secure($data); //secure the query against mysql injections

    abstract public function loadassoc(); //load the result as associative array

    abstract public function loadarray(); //load result as numeric array

    abstract public function loadColumnArray($col); //load given column as array from the result

    abstract public function numRows(); //get number of returned rows

    abstract public function affectedRows();

    abstract public function loadobjectlist(); //load result of query like array full of objects, each object for each row

    abstract public function loadresult($col = NULL); //fetch one result

    abstract public function lastid(); //fetch last inserted autoincrement id for the client

    abstract public function buildQuery($arr, $update = false);

    abstract function reset();
}
