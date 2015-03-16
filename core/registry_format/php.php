<?php

/*
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * 
 */

class RegistryFormatPHP extends RegistryFormat {

    public function objectToString($object, $params = array()) {
        // Build the object variables string
        $vars = '';
        foreach (get_object_vars($object) as $k => $v) {
            if (is_scalar($v)) {
                $vars .= "\tpublic $" . $k . " = '" . addcslashes($v, '\\\'') . "';\n";
            } elseif (is_array($v) || is_object($v)) {
                $vars .= "\tpublic $" . $k . " = " . $this->getArrayString((array) $v) . ";\n";
            }
        }

        $str = "<?php\nclass " . $params['class'] . " {\n";
        $str .= $vars;
        $str .= "}";

        // Use the closing tag if it not set to false in parameters.
        if (!isset($params['closingtag']) || $params['closingtag'] !== false) {
            $str .= "\n?>";
        }

        return $str;
    }

    public function stringToObject($data, $options = array()) {
        return true;
    }

    protected function getArrayString($a) {
        $s = 'array(';
        $i = 0;
        foreach ($a as $k => $v) {
            $s .= ($i) ? ', ' : '';
            $s .= '"' . $k . '" => ';
            if (is_array($v) || is_object($v)) {
                $s .= $this->getArrayString((array) $v);
            } else {
                $s .= '"' . addslashes($v) . '"';
            }
            $i++;
        }
        $s .= ')';
        return $s;
    }

}
