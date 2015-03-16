<?php

abstract class button {

    protected $name = null;
    protected $parent = null;

    public function __construct($parent = null) {
        $this->parent = $parent;
    }

    public function getName() {
        return $this->name;
    }

    public function render($definition) {
        /*
         * Initialize some variables
         */
        $html = null;
        $id = call_user_func_array(array($this, 'fetchId'), $definition);
        $action = call_user_func_array(array($this, 'fetchButton'), $definition);

        // Build id attribute
        if ($id) {
            $id = "id=\"$id\"";
        }

        // Build the HTML Button


        $html .= " <div " . $id . " class=\"btn-group\">\n";
        $html .= $action;
        $html .= "</div>\n";

        return $html;
    }

    public function fetchIconClass($identifier) {
        return "icon-$identifier";
    }

    public function fetchId() {
        return;
    }

    abstract public function fetchButton();
}
