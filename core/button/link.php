<?php

class buttonLink extends button {

    protected $name = 'Link';

    public function fetchButton() {
       
	   $name = func_get_arg(0)?func_get_arg(0):"Back";
	   $text = func_get_arg(1)?func_get_arg(1):"";
	   $url = func_get_arg(2)?func_get_arg(2):null;
	   
	   
        $class = $this->fetchIconClass($name);
        $doTask = $this->getCommand($url);

        $html = "<button class=\"btn btn-small\" onclick=\"location.href='$doTask'; return false; \">\n";
        $html .= "<span class=\"$class\">\n";
        $html .= "</span>\n";
        $html .= "$text\n";
        $html .= "</button>\n";



        return $html;
    }

    public function fetchId() {
        return $this->parent->getName() . '-' . (func_get_arg(0)?func_get_arg(0):"");
    }

    private function getCommand($url) {
        return $url;
    }

}
