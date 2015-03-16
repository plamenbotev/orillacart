<?php

/*
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * 
 */

class RegistryFormatJSON extends RegistryFormat {

    public function objectToString($object, $options = array()) {
        return json_encode($object);
    }

    public function stringToObject($data, $options = array('processSections' => false)) {

        $data = trim($data);
        if ((substr($data, 0, 1) != '{') && (substr($data, -1, 1) != '}')) {
            $ini = RegistryFormat::getInstance('INI');
            $obj = $ini->stringToObject($data, $options);
        } else {
            $obj = json_decode($data);
        }
        return $obj;
    }

}
