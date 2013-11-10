<?php

class buttonSeparator extends button {

    protected $name = 'Separator';

    public function render($definition) {

        $html = null;
        $class = null;
        $style = null;

        // Separator class name
        $class = (empty($definition[1])) ? 'spacer' : $definition[1];
        // Custom width
        $style = (empty($definition[2])) ? null : ' style="width:' . intval($definition[2]) . 'px;"';

        return '<div class="' . $class . '"' . $style . ">\n</div>\n";
    }

    public function fetchButton() {
        
    }

}