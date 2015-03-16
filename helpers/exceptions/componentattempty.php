<?php

class componentAttEmpty extends Exception {

    public function __construct() {

        parent::__construct('The short tag has to contain attribute \'component="com_example"\' ', 0);
    }

}

?>