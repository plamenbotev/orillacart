<?php

class buttonStandard extends button {

    protected $name = 'Standard';

    function fetchButton($type = 'Standard', $name = '', $text = '', $task = '', $list = true, $hideMenu = false) {

        $i18n_text = $text;
        $class = $this->fetchIconClass($name);
        //$doTask = $this->_getCommand($text, $task, $list);

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

    public function fetchId($type = 'Standard', $name = '', $text = '', $task = '', $list = true, $hideMenu = false) {
        return $this->parent->getName() . '-' . $name;
    }

    protected function getCommand($name, $task, $list, $hide) {
        $todo = strings::strtolower($name);
        $message = 'Please make a selection from the list to' . $todo;
        $message = addslashes($message);
        $hidecode = $hide ? 'hideMainMenu();' : '';

        if ($list) {
            $cmd = "javascript:if(document.adminForm.boxchecked.value==0){alert('$message');}else{ $hidecode submitbutton('$task')}";
        } else {
            $cmd = "javascript:$hidecode submitbutton('$task')";
        }


        return $cmd;
    }

}