<?php

class not_found_404 extends Exception {

    public function __construct($m) {

        parent::__construct($m);
    }

    public function __toString() {

        return $this->getMessage();
    }

}