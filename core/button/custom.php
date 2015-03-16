<?php

class buttonCustom extends button {

    protected $name = 'Custom';

    function fetchButton() {
        return func_get_arg(0);
    }

    public function fetchId() {
		
        return $this->parent->name . '-' . (func_get_arg(0)?func_get_arg(0):"custom");
    }

}
