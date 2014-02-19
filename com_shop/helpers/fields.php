<?php

if (!class_exists("fields")) {

    class fields {

        protected $_fields = array();

        public function __construct($fields = array()) {

            foreach ((array) $fields as $k => $v) {
                if (!($v instanceof field))
                    continue;
                $this->_fields[$v->get_name()] = $v;
            }
        }

        public function num_fields() {
            return (int) sizeof($this->_fields);
        }

        public function __get($k) {
            if (array_key_exists($k, $this->_fields)) {
                return $this->_fields[$k];
            }
            return null;
        }

        public function __unset($k) {
            $this->remove_field($k);
        }

        public function __isset($k) {
            if (array_key_exists($k, $this->_fields)) {

                return true;
            } 
            return false;
        }

        public function get_fields() {
            return $this->_fields;
        }

        public function add_field(field $f) {
            $this->_fields[$f->get_name()] = $f;
        }

        public function add_before($key, field $field) {

            if (!array_key_exists($key, $this->_fields) || array_key_exists($field->get_name(), $this->_fields))
                return $this->add_field($field);

            $tmp = array();

            foreach ((array) $this->_fields as $k => $v) {

                if ($k == $key) {
                    $tmp[$field->get_name()] = $field;
                }

                $tmp[$k] = $v;
            }
            $this->_fields = $tmp;
            unset($tmp);
            return true;
        }

        public function add_after($key, field $field) {

            if (!array_key_exists($key, $this->_fields) || array_key_exists($field->get_name(), $this->_fields))
                return $this->add_field($field);

            $tmp = array();

            foreach ((array) $this->_fields as $k => $v) {


                $tmp[$k] = $v;

                if ($k == $key) {
                    $tmp[$field->get_name()] = $field;
                }
            }
            $this->_fields = $tmp;
            unset($tmp);
            return true;
        }

        public function remove_field($name) {
			
		    if (array_key_exists($name, $this->_fields)) {
                unset($this->_fields[$name]);
                return true;
            }
            return false;
        }

        public function get_field() {
            $f = current($this->_fields);
            next($this->_fields);

            if (!$f) {
                reset($this->_fields);
                return false;
            }
            return $f;
        }

        public function reset() {
            reset($this->_fields);
        }

    }

}

if (!class_exists("field")) {

    abstract class field {

        protected $_required = false;
        protected $_name = null;
        protected $_params = array();
        protected $_value = '';
        protected $_classes = array();
        protected $_label = '';
        protected $_error_msg = '';
        protected $_val_callback = array();
		protected $_exclude = false;
		
		public function exclude(){
			$this->_exclude = true;
			$this->set_required(false);
		}
		
		public function is_excluded(){
			return (bool)$this->_exclude;
		}

        public function add_validation($v = array()) {
            if (is_callable($v)) {
                $this->_val_callback = $v;
            }
            return $this;
        }

        final protected function getApp() {
            static $app = null;
            if ($app instanceof component)
                return $app;
            else {
                $app = Factory::getApplication('shop');
                return $app;
            }
        }

        public function set_error_msg($msg) {
            $this->_error_msg = $msg;
            return $this;
        }

        public function get_error_msg() {
            return $this->_error_msg;
        }

        public function add_class($c) {
            if (is_array($c)) {
                $this->_classes = (array) array_merge((array) $this->_classes, (array) $c);
                return $this;
            }
            $this->_classes[] = $c;
            return $this;
        }

        public static function _($class, $name) {
            return new $class($name);
        }

        public function set_label($label) {
            $this->_label = $label;
            return $this;
        }

        public function get_label() {
            return $this->_label;
        }

        public function add_param($p, $v) {
            $p = strtolower($p);
            if ($p == 'class' || $p == 'value')
                return $this;

            $this->_params[$p] = $v;
            return $this;
        }

        public function set_value($v) {
            $this->_value = $v;
            return $this;
        }

        public function get_value() {
            return $this->_value;
        }

        public function set_required($bool = true) {
            $this->_required = (bool) $bool;
            $this->add_class('required');
            return $this;
        }

        public function __construct($name) {

            $this->_name = $name;
        }

        public function get_type() {
            return get_class($this);
        }

        public function required() {
            return (bool) $this->_required;
        }

        public function get_name() {
            return $this->_name;
        }

        public function get_param($k) {
            if (isset($this->_params[$k]))
                return $this->_params[$k];
            return '';
        }

        public function remove_param($k) {
            if (isset($this->_params[$k]))
                unset($this->_params[$k]);
            return $this;
        }

        abstract public function render();

        public function validate() {

            if ($this->required()) {
                if (empty($this->_value)) {

                    return false;
                } elseif (!empty($this->_val_callback)) {

                    return (bool) call_user_func_array($this->_val_callback, array($this->get_value()));
                }
            }
            return true;
        }

        public function __toString() {
            return (string) $this->render();
        }

    }

}

if (!class_exists("text")) {

    class text extends field {

        public function __construct($name) {
            parent::__construct($name);
        }

        public function render() {
            $html .="<input id='" . $this->get_name() . "' type='text' name='" . $this->get_name() . "'";
            foreach ((array) $this->_params as $k => $v) {
                $html .= " " . $k . "='" . $v . "' ";
            }
            $html .= " value='" . $this->get_value() . "' ";

            if (!empty($this->_classes)) {
                $html .= " class='" . implode(' ', $this->_classes) . "' ";
            }

            $html .=" />";
            return $html;
        }

    }

}
if (!class_exists("select")) {

    class select extends field {

        protected $_options = array();
        protected $_selected = null;

        public function __construct($name, $options = array()) {
            if (!empty($options))
                $this->_options = $options;

            parent::__construct($name);
        }

        public function render() {
            $html .="<select id='" . $this->get_name() . "' name='" . $this->get_name() . "'";
            foreach ((array) $this->_params as $k => $v) {
                $html .= " " . $k . "='" . $v . "' ";
            }
            if (!empty($this->_classes)) {
                $html .= " class='" . implode(" ", $this->_classes) . "' ";
            }

            $html .=">" . "\n";

            foreach ((array) $this->_options as $k => $v) {
                $html .= "<option value='" . $k . "'";
                if ($k == $this->get_value()) {
                    $html .= " selected='selected'";
                }
                $html .= ">" . $v . "</option>";
            }
            $html .= "</select>";
            return $html;
        }

        public function get_text($opt) {
            if (!empty($opt)) {
                if (isset($this->_options[$opt])) {
                    return $this->_options[$opt];
                }
                return null;
            } else {
                if ($this->get_value()) {
                    return $this->_options[$this->get_value()];
                }
                return null;
            }

            return null;
        }

        public function set_options(array $o) {
            $this->_options = $o;
        }

    }

}


if (!class_exists('country')) {

    class country extends select {

        public function __construct($name) {

            $db = Factory::getDBO();

            $allowed_countries = (array) Factory::getApplication('shop')->getPArams()->get('retail_countries');

            $ids = array_map(array($db, 'secure'), $allowed_countries);
            $where = '';
            if (empty($ids)) {

                $where = "1";
            } else {

                $where = "country_2_code IN('" . implode("','", $ids) . "')";
            }

            $que = "SELECT  * FROM `#_shop_country` WHERE " . $where . " ORDER BY country_name ASC ";



            $db->setQuery($que);

            if (!$db->getResource()) {

                throw new Exception($db->getErrorString());
            }

            $rows = $db->loadObjectList();


            foreach ((array) $rows as $row) {
                $this->_options[$row->country_2_code] = stripslashes($row->country_name);
            }


            parent::__construct($name);
        }

    }

}


if (!class_exists('state')) {

    class state extends field {

        protected $_options = array();
        protected $_country = null;

        public function __construct($name) {


            parent::__construct($name);
        }

        public function render() {

            $db = Factory::getDBO();
            $que = "SELECT  * FROM `#_shop_state` WHERE country_id= '" . $db->secure($this->_country) . "' ORDER BY state_name ASC ";
            $db->setQuery($que);

            if (!$db->getResource()) {

                throw new Exception($db->getErrorString());
            }

            $rows = $db->loadObjectList();

            if (!empty($rows)) {


                foreach ((array) $rows as $row) {
                    $this->_options[stripslashes($row->state_2_code)] = stripslashes($row->state_name);
                }
            }
            if (!empty($this->_options)) {

                $html .="<select id='" . $this->get_name() . "' name='" . $this->get_name() . "'";
                foreach ((array) $this->_params as $k => $v) {
                    $html .= " " . $k . "='" . $v . "' ";
                }
                if (!empty($this->_classes)) {
                    $html .= " class='" . implode(" ", $this->_classes) . "' ";
                }

                $html .=">" . "\n";

                foreach ((array) $this->_options as $k => $v) {
                    $html .= "<option value='" . $k . "'";
                    if ($k == $this->get_value()) {
                        $html .= " selected='selected'";
                    }
                    $html .= ">" . $v . "</option>";
                }
                $html .="</select>";
            } else {

                $html = "<input id='" . $this->get_name() . "' type='text' name='" . $this->get_name() . "' value='" . $this->get_value() . "'";

                foreach ((array) $this->_params as $k => $v) {
                    $html .= " " . $k . "='" . $v . "' ";
                }


                if (!empty($this->_classes)) {
                    $html .= " class='" . implode(' ', $this->_classes) . "' ";
                }

                $html .=" />";
            }
            return $html;
        }

        public function get_country() {
            return $this->_country;
        }

        public function set_country($id) {
            $validation = Factory::getApplication('shop')->getHelper('validation');
            if (!$validation->is_country($id)) {
                $this->_country = null;
                return $this;
            }
            $this->_country = $id;
            return $this;
        }

    }

}