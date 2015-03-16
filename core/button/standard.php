<?php

class buttonStandard extends button {

    protected $name = 'Standard';

    public function fetchButton() {
		
		$args = func_get_args();
		
		$name = isset($args[0])? $args[0] : "";
		$text = isset($args[1])? $args[1] : "";
		$task = isset($args[2])? $args[2] : "";
	
        $i18n_text = $text;
        $class = $this->fetchIconClass($name);
       

        if ($name == "apply" || $name == "new") {
            $btnClass = "btn btn-small btn-success";
            $iconWhite = "icon-white";
        } else {
            $btnClass = "btn btn-small";
            $iconWhite = "";
        }

        $html = "<button href=\"#\" onclick=\"$task\" class=\"" . $btnClass . "\">\n";
        $html .= "<i class=\"$class $iconWhite\">\n";
        $html .= "</i>\n";
        $html .= "$i18n_text\n";
        $html .= "</button>\n";

        return $html;
    }

    public function fetchId() {
        return $this->parent->getName() . '-' . func_get_arg(0);
    }

}
