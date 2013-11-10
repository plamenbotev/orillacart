<?php

class buttonLink extends button {

    protected $name = 'Link';

    function fetchButton($type = 'Link', $name = 'back', $text = '', $url = null) {
        //$text	= JText::_($text);
        $class = $this->fetchIconClass($name);
        $doTask = $this->getCommand($url);

        $html = "<button class=\"btn btn-small\" onclick=\"location.href='$doTask'; return false; \">\n";
        $html .= "<span class=\"$class\">\n";
        $html .= "</span>\n";
        $html .= "$text\n";
        $html .= "</button>\n";



        return $html;
    }

    function fetchId($name) {
        return $this->parent->getName() . '-' . $name;
    }

    private function getCommand($url) {
        return $url;
    }

}