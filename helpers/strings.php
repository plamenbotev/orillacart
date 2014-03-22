<?php

defined("_VALID_EXEC") or die("access denied");

define('encoding', get_option('blog_charset'));

final class strings {

    static public function htmlentities($string) {


        return htmlentities(self::html_entity_decode($string), ENT_QUOTES, encoding);
    }

    static public function html_entity_decode($string) {

        return html_entity_decode($string, ENT_QUOTES, encoding);
    }

    static public function substr($string, $start, $end) {

        return mb_substr($string, $start, $end, encoding);
    }

    static public function strtolower($string) {

        return mb_strtolower($string, encoding);
    }

    static public function strtoupper($string) {

        return mb_strtoupper($string, encoding);
    }

    static public function stristr($haystack, $needle, $before_needle = false) {

        return mb_stristr($haystack, $needle, $before_needle, encoding);
    }

    static public function strlen($string) {

        return mb_strlen($string, encoding);
    }

    static public function stripos($string, $needle, $pos = 0) {

        return mb_stripos($string, $needle, $pos, encoding);
    }

    static public function stripandencode($string) {


        return htmlentities(stripslashes($string), ENT_QUOTES, encoding);
    }

    static public function show($object, $default = null, $strip_slashes = true, $type = false) {

        if (!isset($object) || empty($object))
            return self::htmlentities($default);



        if ($type !== false && in_array($type, array("boolean", "bool", "integer", "int", "float", "string", "null"))) {

            if (!settype($object, $type)) {

                return self::htmlentities($default);
            }
        } else {

            if (!$strip_slashes)
                return self::htmlentities($object);
            else
                return self::stripAndEncode($object);
        }



        return $object;
    }

    static public function validate_mail($mail) {


        $expr = "/^((\\\"[^\\\"\\f\\n\\r\\t\\b]+\\\")|([A-Za-z0-9_][A-Za-z0-9_\\!\\#\\$\\%\\&\\'\\*\\+\\-\\~\\/\\^\\`\\|\\{\\}]*(\\.[A-Za-z0-9_\\!\\#\\$\\%\\&\\'\\*\\+\\-\\~\\/\\^\\`\\|\\{\\}]*)*))@((\\[(((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9]))\\.((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9]))\\.((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9]))\\.((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9])))\\])|(((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9]))\\.((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9]))\\.((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9]))\\.((25[0-5])|(2[0-4][0-9])|([0-1]?[0-9]?[0-9])))|((([A-Za-z0-9])(([A-Za-z0-9\\-])*([A-Za-z0-9]))?(\\.(?=[A-Za-z0-9\\-]))?)+[A-Za-z]+))$/D";


        if (preg_match($expr, $mail))
            return true;


        return false;
    }

    static public function getShortcodeRegex($tag = 'framework') {

        return '(.?)\[(' . preg_quote($tag) . ')\b(.*?)(?:(\/))?\](?:(.+?)\[\/\2\])?(.?)';
    }

    static public function cleanText(&$text) {
        $text = preg_replace("'<script[^>]*>.*?</script>'si", '', $text);
        $text = preg_replace('/<a\s+.*?href="([^"]+)"[^>]*>([^<]+)<\/a>/is', '\2 (\1)', $text);
        $text = preg_replace('/<!--.+?-->/', '', $text);
        $text = preg_replace('/{.+?}/', '', $text);
        $text = preg_replace('/&nbsp;/', ' ', $text);
        $text = preg_replace('/&amp;/', ' ', $text);
        $text = preg_replace('/&quot;/', ' ', $text);
        $text = strip_tags($text);
        $text = self::htmlentities($text);
        return $text;
    }

    static public function wordLimit($str, $limit = 100, $end_char = '&#8230;') {
        if (trim($str) == '')
            return $str;

        // always strip tags for text
        $str = strip_tags($str);

        $find = array("/\r|\n/", "/\t/", "/\s\s+/");
        $replace = array(" ", " ", " ");
        $str = preg_replace($find, $replace, $str);

        preg_match('/\s*(?:\S*\s*){' . (int) $limit . '}/', $str, $matches);
        if (strlen($matches[0]) == strlen($str))
            $end_char = '';
        return rtrim($matches[0]) . $end_char;
    }

    // Character limit
    static public function characterLimit($str, $limit = 150, $end_char = '...') {
        if (trim($str) == '')
            return $str;

        // always strip tags for text
        $str = strip_tags(trim($str));

        $find = array("/\r|\n/", "/\t/", "/\s\s+/");
        $replace = array(" ", " ", " ");
        $str = preg_replace($find, $replace, $str);

        if (strlen($str) > $limit) {
            if (function_exists("mb_substr")) {
                $str = mb_substr($str, 0, $limit);
            } else {
                $str = substr($str, 0, $limit);
            }
            return rtrim($str) . $end_char;
        } else {
            return $str;
        }
    }

    public static function parse_url($url) {
        $result = array();
        // Build arrays of values we need to decode before parsing
        $entities = array('%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B',
            '%5D');
        $replacements = array('!', '*', "'", "(", ")", ";", ":", "@", "&", "=", "$", ",", "/", "?", "%", "#", "[", "]");
        // Create encoded URL with special URL characters decoded so it can be parsed
        // All other characters will be encoded
        $encodedURL = str_replace($entities, $replacements, urlencode($url));
        // Parse the encoded URL
        $encodedParts = parse_url($encodedURL);
        // Now, decode each value of the resulting array
        foreach ($encodedParts as $key => $value) {
            $result[$key] = urldecode($value);
        }
        return $result;
    }

}

?>