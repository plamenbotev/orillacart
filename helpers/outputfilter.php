<?php

/*
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

class OutputFilter {

    static public function objectHTMLSafe(&$mixed, $quote_style = ENT_QUOTES, $exclude_keys = '') {
        if (is_object($mixed)) {
            foreach (get_object_vars($mixed) as $k => $v) {
                if (is_array($v) || is_object($v) || $v == NULL || substr($k, 1, 1) == '_') {
                    continue;
                }

                if (is_string($exclude_keys) && $k == $exclude_keys) {
                    continue;
                } else if (is_array($exclude_keys) && in_array($k, $exclude_keys)) {
                    continue;
                }

                $mixed->$k = strings::htmlentities($v, $quote_style);
            }
        }
    }

    static public function linkXHTMLSafe($input) {
        $regex = 'href="([^"]*(&(amp;){0})[^"]*)*?"';
        return preg_replace_callback("#$regex#i", array('self', '_ampReplaceCallback'), $input);
    }

    static public function stringURLSafe($title) {

        $title = self::cleanText($title);
        // Preserve escaped octets.
        $title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title);
        // Remove percent signs that are not part of an octet.
        $title = str_replace('%', '', $title);
        // Restore octets.
        $title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title);

        if (self::is_utf8($title)) {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title, 'UTF-8');
            }
            $title = self::utf8_uri_encode($title);
        }

        $title = strtolower($title);
        $title = preg_replace('/&.+?;/', '', $title); // kill entities
        $title = str_replace('.', '-', $title);
        $title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);
        $title = preg_replace('|-+|', '-', $title);
        $title = trim($title, '-');

        return $title;
    }

    static public function is_utf8($str) {
        $length = strlen($str);
        for ($i = 0; $i < $length; $i++) {
            $c = ord($str[$i]);
            if ($c < 0x80)
                $n = 0;# 0bbbbbbb
            elseif (($c & 0xE0) == 0xC0)
                $n = 1;# 110bbbbb
            elseif (($c & 0xF0) == 0xE0)
                $n = 2;# 1110bbbb
            elseif (($c & 0xF8) == 0xF0)
                $n = 3;# 11110bbb
            elseif (($c & 0xFC) == 0xF8)
                $n = 4;# 111110bb
            elseif (($c & 0xFE) == 0xFC)
                $n = 5;# 1111110b
            else
                return false;# Does not match any model
            for ($j = 0; $j < $n; $j++) { # n bytes matching 10bbbbbb follow ?
                if (( ++$i == $length) || ((ord($str[$i]) & 0xC0) != 0x80))
                    return false;
            }
        }
        return true;
    }

    static public function utf8_uri_encode($utf8_string, $length = 0) {
        $unicode = '';
        $values = array();
        $num_octets = 1;
        $unicode_length = 0;

        $string_length = strlen($utf8_string);
        for ($i = 0; $i < $string_length; $i++) {

            $value = ord($utf8_string[$i]);

            if ($value < 128) {
                if ($length && ( $unicode_length >= $length ))
                    break;
                $unicode .= chr($value);
                $unicode_length++;
            } else {
                if (count($values) == 0)
                    $num_octets = ( $value < 224 ) ? 2 : 3;

                $values[] = $value;

                if ($length && ( $unicode_length + ($num_octets * 3) ) > $length)
                    break;
                if (count($values) == $num_octets) {
                    if ($num_octets == 3) {
                        $unicode .= '%' . dechex($values[0]) . '%' . dechex($values[1]) . '%' . dechex($values[2]);
                        $unicode_length += 9;
                    } else {
                        $unicode .= '%' . dechex($values[0]) . '%' . dechex($values[1]);
                        $unicode_length += 6;
                    }

                    $values = array();
                    $num_octets = 1;
                }
            }
        }

        return $unicode;
    }

    static public function ampReplace($text) {
        $text = str_replace('&&', '*--*', $text);
        $text = str_replace('&#', '*-*', $text);
        $text = str_replace('&amp;', '&', $text);
        $text = preg_replace('|&(?![\w]+;)|', '&amp;', $text);
        $text = str_replace('*-*', '&#', $text);
        $text = str_replace('*--*', '&&', $text);

        return $text;
    }

    static public function _ampReplaceCallback($m) {
        $rx = '&(?!amp;)';
        return preg_replace('#' . $rx . '#', '&amp;', $m[0]);
    }

    /**
     * Cleans text of all formating and scripting code
     */
    static public function cleanText(&$text) {
        return strings::cleanText($text);
    }

}
