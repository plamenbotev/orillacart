<?php

defined('_VALID_EXEC') or die('access denied');

class Factory {

    static public function getDBO($type = null) {
		
		global $wpdb;
		
		if($type == null){
			
			if ( ! isset($wpdb->use_mysqli) || ! $wpdb->use_mysqli ){
				$type = "mysql";
			}else{
			
				$type = "mysqliDriver";
				
			}
			
		}
	
        if (class_exists($type) && method_exists($type, 'getInstance'))
            return call_user_func($type . '::getInstance');
        else
            throw new Exception("database driver not found!");
    }

    static public function getFramework() {
        return framework::getInstance();
    }

    static public function getMainframe() {
        return mainframe::getInstance();
    }

    static public function getParams($com) {
        return self::getApplication($com)->getParams();
    }

    static public function getApplication($component) {

        static $apps = array();

        if (array_key_exists($component, $apps)) {
            do_action("before_init_and_cache_" . $component);
            return $apps[$component];
        }


        $components = self::getFramework()->get_active_components();

        foreach ((array) $components as $k => $v) {
            if ($v == $component) {
                do_action("before_init_" . $v);
                $com = new $v();
                do_action("after_init_" . $com->getName());
                return $apps[$component] = $com;
            }
        }

        wp_die(_('no such component:' . $component));
        exit;
    }

    static public function getXML($data, $isFile = true) {

        // Disable libxml errors and allow to fetch error information as needed
        libxml_use_internal_errors(true);

        if ($isFile) {
            // Try to load the XML file
            $xml = simplexml_load_file($data, 'JSimpleXMLElement');
        } else {
            // Try to load the XML string
            $xml = simplexml_load_string($data, 'JSimpleXMLElement');
        }

        if (empty($xml)) {

            $error = '';
            foreach (libxml_get_errors() as $error) {
                $error .= 'XML: ' . $error->message . "\n";
            }
            throw new xml_exception($error);
        }

        return $xml;
    }

    static public function getLogger($file) {
        return new Logger($file);
    }

}