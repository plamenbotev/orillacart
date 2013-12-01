<?php

class ArrayHelper {

    static public function toInt($array, $default = null) {
        if (is_array($array)) {

            $array = array_map('intval', $array);
        } else {
            if ($default === null) {
                $array = array();
            } elseif (is_array($default)) {
                self::toInteger($default, null);
                $array = $default;
            } else {
                $array = array((int) $default);
            }
        }
        return $array;
    }

    static public function toObject(&$array, $class = 'stdClass') {
        $obj = null;
        if (is_array($array)) {
            $obj = new $class();
            foreach ($array as $k => $v) {
                if (is_array($v)) {
                    $obj->$k = self::toObject($v, $class);
                } else {
                    $obj->$k = $v;
                }
            }
        }
        return $obj;
    }

    static public function fromObject($p_obj, $recurse = true, $regex = null) {
        $result = null;
        if (is_object($p_obj)) {
            $result = array();
            foreach (get_object_vars($p_obj) as $k => $v) {
                if ($regex) {
                    if (!preg_match($regex, $k)) {
                        continue;
                    }
                }
                if (is_object($v)) {
                    if ($recurse) {
                        $result[$k] = self::fromObject($v, $recurse, $regex);
                    }
                } else {
                    $result[$k] = $v;
                }
            }
        }
        return $result;
    }

    static public function getColumn(&$array, $index) {
        $result = array();

        if (is_array($array)) {
            $n = count($array);
            for ($i = 0; $i < $n; $i++) {
                $item = & $array[$i];
                if (is_array($item) && isset($item[$index])) {
                    $result[] = $item[$index];
                } elseif (is_object($item) && isset($item->$index)) {
                    $result[] = $item->$index;
                }
                // else ignore the entry
            }
        }
        return $result;
    }

    static public function getValue($array, $name, $default = null, $type = '') {
        // Initialize variables
        $result = null;

        if (isset($array[$name])) {
            $result = $array[$name];
        }

        // Handle the default case
        if (is_null($result)) {
            $result = $default;
        }

        // Handle the type constraint
        switch (strtoupper($type)) {
            case 'INT' :
            case 'INTEGER' :
                // Only use the first integer value
                @ preg_match('/-?[0-9]+/', $result, $matches);
                $result = @ (int) $matches[0];
                break;

            case 'FLOAT' :
            case 'DOUBLE' :
                // Only use the first floating point value
                @ preg_match('/-?[0-9]+(\.[0-9]+)?/', $result, $matches);
                $result = @ (float) $matches[0];
                break;

            case 'BOOL' :
            case 'BOOLEAN' :
                $result = (bool) $result;
                break;

            case 'ARRAY' :
                if (!is_array($result)) {
                    $result = array($result);
                }
                break;

            case 'STRING' :
                $result = (string) $result;
                break;

            case 'WORD' :
                $result = (string) preg_replace('#\W#', '', $result);
                break;

            case 'NONE' :
            default :
                // No casting necessary
                break;
        }
        return $result;
    }

    static public function compare(array $a, array $b) {

        $tmp = array();

        $tmp = (array) array_merge(
                        (array) array_diff($a, $b), (array) array_diff($b, $a)
        );

        if (!empty($tmp))
            return (array) array_unique($tmp);

        return array();
    }

    public static function isAssociative($array) {
        if (is_array($array)) {
            foreach (array_keys($array) as $k => $v) {
                if ($k !== $v) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function arrayUnique($myArray) {
        if (!is_array($myArray)) {
            return $myArray;
        }

        foreach ($myArray as $k => $myvalue) {
            $myArray[$k] =  serialize($myvalue);
        }

        $myArray = array_unique($myArray);

        foreach ($myArray as $k => $myvalue) {
            $myArray[$k] = unserialize($myvalue);
        }

        return $myArray;
    }

}