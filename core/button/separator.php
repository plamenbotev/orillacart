<?php

class buttonSeparator extends button {

    protected $name = 'Separator';

    public function fetchButton() {

        $html = null;
        $class = null;
        $style = null;

		$definition = func_get_args();
		
        // Separator class name
        $class = (empty($definition[0])) ? 'spacer' : $definition[0];
        // Custom width
        $style = (empty($definition[1])) ? null : ' style="width:' . intval($definition[1]) . 'px;"';

        return '<div class="' . $class . '"' . $style . ">\n</div>\n";
    }

    

}
