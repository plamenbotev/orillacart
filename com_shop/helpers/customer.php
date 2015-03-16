<?php

class customer {

    protected $fields = null;
    protected $ship_to_billing = false;

    public function ship_to_billing() {
        return (bool) $this->ship_to_billing;
    }

    public function get($key, $def = null) {
        $type = 'billing';
        if (stripos($key, 'shipping_') !== false) {
            $type = 'shipping';
        }

        $fields = $this->fields[$type];

        if (isset($fields->{$key})) {

            $val = $fields->{$key}->get_value();
            if (empty($val))
                return $def;

            return $val;
        }
        return $def;
    }

    public function get_billing() {
        return $this->fields['billing'];
    }

    public function get_shipping() {
        return $this->fields['shipping'];
    }

    public function get_billing_fields() {

        $validation = Helper::getInstance("validation", "shop");

        $type = 'billing';
        $fields = new fields(array(
                    field::_('text', $type . '_first_name')->add_class('billibg_field form-control')
                    ->set_required()
                    ->add_param('placeholder', __('First Name', 'com_shop'))
                    ->set_label(__('First Name', 'com_shop'))
                    ->set_error_msg(__("Please enter first name", "com_shop")),
                    field::_('text', $type . '_last_name')->add_class('billibg_field form-control')
                    ->set_required()
                    ->add_param('placeholder', __('Last Name', 'com_shop'))
                    ->set_label(__('Last Name', 'com_shop'))
                    ->set_error_msg(__("Please enter last name", "com_shop")),
                    field::_('text', $type . '_address')->add_class('billibg_field form-control')
                    ->set_required()
                    ->add_param('placeholder', __('Address', 'com_shop'))
                    ->set_error_msg(__("Please enter valid address.", "com_shop"))
                    ->set_label(__('Address', 'com_shop')),
                    field::_('text', $type . '_city')->add_class('form-control billibg_field')
                    ->set_required()
                    ->set_error_msg(__("Please enter valid city.", "com_shop"))
                    ->add_param('placeholder', __('City', 'com_shop'))
                    ->set_label(__('City', 'com_shop')),
                    field::_('text', $type . '_zipcode')->add_class('form-control billibg_field')
                    ->set_required()
                    ->set_error_msg(__("Please enter valid zipcode.", 'com_shop'))
                    ->add_param('placeholder', __('Post Code', 'com_shop'))
                    ->set_label(__('Post/Zip Code', 'com_shop'))
                    ->add_validation(array($validation, 'is_zipcode')),
                    field::_('country', $type . '_country')->add_class('form-control refresh-' . $type . '-states billibg_field')
                    ->set_required()
                    ->set_error_msg(__("Please select country.", 'com_shop'))
                    ->set_label(__('Country', 'com_shop')),
                    field::_('state', $type . '_state')->add_class($type . '-states billibg_field form-control')
                    ->set_required()
                    ->set_error_msg(__("Please select state.", "com_shop"))
                    ->set_label(__('State', 'com_shop')),
                    field::_('text', 'billing_email')->add_class('form-control billibg_field')
                    ->set_required()
                    ->add_param('placeholder', __('Email', 'com_shop'))
                    ->set_label(__('Email', 'com_shop'))
                    ->set_error_msg(__("Please enter valid email.", "com_shop"))
                    ->add_validation(array($validation, 'is_email')),
                    field::_('text', 'billing_phone')->add_class('form-control billibg_field')
                    ->set_required()
                    ->add_param('placeholder', __('Phone', 'com_shop'))
                    ->set_label(__('Phone', 'com_shop'))
                    ->set_error_msg(__("Please enter valid phone.", "com_shop"))
                    ->add_validation(array($validation, 'is_phone'))
        ));

        do_action("extend_billing_fields", $fields);
        return $fields;
    }

    public function get_shipping_fields() {
        $type = 'shipping';

        $validation = Helper::getInstance("validation", "shop");

        $fields = new fields(array(
                    field::_('text', $type . '_first_name')->add_class('form-control shipping_field')
                    ->set_required()
                    ->add_param('placeholder', __('First Name', 'com_shop'))
                    ->set_label(__('First Name', 'com_shop'))
                    ->set_error_msg(__("Please enter first name", "com_shop")),
                    field::_('text', $type . '_last_name')->add_class('form-control shipping_field')
                    ->set_required()
                    ->add_param('placeholder', __('Last Name', 'com_shop'))
                    ->set_label(__('Last Name', 'com_shop'))
                    ->set_error_msg(__("Please enter last name", "com_shop")),
                    field::_('text', $type . '_address')->add_class('form-control shipping_field')
                    ->set_required()
                    ->add_param('placeholder', __('Address', 'com_shop'))
                    ->set_error_msg(__("Please enter valid address.", "com_shop"))
                    ->set_label(__('Address', 'com_shop')),
                    field::_('text', $type . '_city')->add_class('form-control shipping_field')
                    ->set_required()
                    ->set_error_msg(__("Please enter valid city.", 'com_shop'))
                    ->add_param('placeholder', __('City', 'com_shop'))
                    ->set_label(__('City', 'com_shop')),
                    field::_('text', $type . '_zipcode')->add_class('form-control shipping_field')
                    ->set_required()
                    ->set_error_msg(__("Please enter valid zipcode.", "com_shop"))
                    ->add_param('placeholder', __('Post Code', 'com_shop'))
                    ->set_label(__('Post/Zip Code', 'com_shop'))
                    ->add_validation(array($validation, 'is_zipcode')),
                    field::_('country', $type . '_country')->add_class('form-control refresh-' . $type . '-states shipping_field')
                    ->set_required()
                    ->set_error_msg(__("Please select country.", "com_shop"))
                    ->set_label(__('Country', 'com_shop')),
                    field::_('state', $type . '_state')->add_class($type . '-states shipping_field form-control')
                    ->set_required()
                    ->set_error_msg(__("Please select state.", "com_shop"))
                    ->set_label(__('State', 'com_shop')),
                    field::_('text', 'shipping_phone')->add_class('form-control shipping_field')
                    ->set_required()
                    ->add_param('placeholder', __('Shipping Phone', 'com_shop'))
                    ->set_label(__('Phone', 'com_shop'))
                    ->set_error_msg(__("Please enter valid phone.", "com_shop"))
                    ->add_validation(array($validation, 'is_phone'))
        ));

        do_action("extend_shipping_fields", $fields);

        return $fields;
    }

    protected function generate_customer_fields() {

        $validation = Factory::getComponent('shop')->getHelper('validation');

        $this->fields['billing'] = $this->get_billing_fields();
        $this->fields['shipping'] = $this->get_shipping_fields();

        $this->fill_values();
    }

    public function fill_values() {

        $input = Factory::getApplication()->getInput();

        if (strtolower($input->getMethod()) == 'post') {
            if (isset($input->post['ship_to_billing'])) {
                $this->ship_to_billing = true;
            }
        } elseif (isset($_SESSION['customer']['ship_to_billing'])) {
            $this->ship_to_billing = (bool) $_SESSION['customer']['ship_to_billing'];
        } else {

            if (is_user_logged_in()) {
                $this->ship_to_billing = get_user_meta(get_current_user_id(), '_ship_to_billing', true);
            }
        }

        if ($input->get('con', null, "CMD") == 'cart' &&
                $input->get('task', null, "CMD") == 'checkout' &&
                !Factory::getApplication()->is_admin() &&
                $input->getMethod() == 'POST') {
            $_SESSION['customer']['ship_to_billing'] = $this->ship_to_billing;
        }
        foreach (array('billing', 'shipping') as $type) {

            while ($field = $this->fields[$type]->get_field()) {

                $val = null;
                $fkey = null;

                if ($type == 'shipping' && $this->ship_to_billing()) {
                    $fkey = str_replace('shipping_', 'billing_', $field->get_name());
                } else {
                    $fkey = $field->get_name();
                }


                if (isset($input->post[$fkey])) {
                    $val = $input->post[$fkey];
                } elseif (isset($_SESSION['customer'][$type][$field->get_name()])) {
                    $val = $_SESSION['customer'][$type][$field->get_name()];
                } else if (is_user_logged_in() || $field->get_name() == $type . "_country") {


                    $val = get_user_meta(get_current_user_id(), "_" . $fkey, true);

                    if ($field->get_name() == $type . "_country" && empty($val)) {
                        $val = Factory::getComponent('shop')->getParams()->get('shop_country');
                    }
                }

                $field->set_value($val);
                if ($input->get('con', null, "CMD") == 'cart' &&
                        $input->get('task', null, "CMD") == 'checkout' &&
                        !Factory::getApplication()->is_admin() &&
                        $input->getMethod() == 'POST') {
                    $_SESSION['customer'][$type][$field->get_name()] = $val;
                }
            }

            if (isset($this->fields[$type]->{$type . "_state"})) {
                if (isset($this->fields[$type]->{$type . "_country"})) {

                    $this->fields[$type]->{$type . "_state"}->set_country($this->fields[$type]->{$type . "_country"}->get_value());
                } else {
                    $this->fields[$type]->{$type . "_state"}->set_country(Factory::getComponent('shop')->getParams()->get('shop_country'));
                }
            }
        }
    }

    protected function __construct() {
        if (!session_id())
            session_start();
    }

    public function init() {
        if (!Factory::getApplication()->is_admin())
            $this->generate_customer_fields();
    }

    public static function getInstance() {

        static $instance = null;
        if ($instance instanceof self)
            return $instance;

        return $instance = new self();
    }

    public function create_user($data) {

        $def = array(
            'username' => '',
            'email' => '',
            'password' => '',
            'password-2' => ''
        );

        $data = wp_parse_args($data, $def);


        $reg_errors = new WP_Error();
		
		$filter = FilterInput::getInstance();
		
		$data['username'] = $filter->clean($data['username'], 'USERNAME');
		
		do_action( 'register_post', $data['username'], $data['email'],$reg_errors );
		
		$reg_errors = apply_filters( 'registration_errors', $reg_errors, $data['username'], $data['email']);
				


        if ($reg_errors->get_error_code()) {
            throw new Exception(__($reg_errors->get_error_message(), 'com_shop'));
        }

      

       
        if (empty($data['username'])) {
            throw new Exception(__("Please enter valid username!", "com_shop"));
        }

        if (!is_email($data['email'])) {
            throw new Exception(__('Please provide valid e-mail address!', 'com_shop'));
        }
        $data['password'] = $filter->clean($data['password'], 'STRING');
        $data['password-2'] = $filter->clean($data['password-2'], 'STRING');

        if (empty($data['password']) || $data['password'] !== $data['password-2']) {
            throw new Exception(__("Please provide valid password!", "com_shop"));
        }

        // Check the username
        if (!validate_username($data['username'])) {
            throw new Exception(__('Invalid email/username', 'com_shop'));
        } elseif (username_exists($data['username'])) {
            throw new Exception(__('An account is already registered with that username. Please choose another.', 'com_shop'));
        }

        // Check the e-mail address
        if (email_exists($data['email'])) {
            throw new Exception(__('An account is already registered with your email address. Please login.', 'com_shop'));
        }

        $user_id = wp_create_user($data['username'], $data['password'], $data['email']);
        if (!$user_id) {
            throw new Exception(__('Couldn&#8217;t register you...', 'com_shop'));
        }

        wp_update_user(array('ID' => $user_id, 'role' => 'customer'));
        // send the user a confirmation and their login details
        wp_new_user_notification($user_id, $data['password']);

		 wp_set_current_user($user_id, $data['username']);
	

		
		
        // set the WP login cookie
        wp_set_auth_cookie($user_id, true, (bool) is_ssl());
		
		do_action('wp_login', $data['username']); 

        return $user_id;
    }

    public function format_shipping(array $data) {

        $oshipping = $this->get_shipping_fields();

        while ($f = $oshipping->get_field()) {
            if (isset($data[$f->get_name()])) {
                $f->set_value($data[$f->get_name()]);
            }
        }


        if (isset($oshipping->shipping_country) && isset($oshipping->shipping_state)) {
            $oshipping->shipping_state->set_country($oshipping->shipping_country->get_value());
        }


        $format = apply_filters('shipping_format', array(
            'shipping_first_name',
            'shipping_last_name',
            'shipping_country',
            'shipping_state',
            'shipping_address',
            'shipping_city'
                )
        );

        $formatted = array();

        foreach ((array) $format as $ff) {

            if (isset($oshipping->{$ff})) {
                if ($oshipping->{$ff} instanceof country) {

                    $country = Factory::getComponent('shop')->getTable('country')->load_by_code($oshipping->{$ff}->get_value());
                    $formatted[] = strings::htmlentities($country->country_name);
                } elseif ($oshipping->{$ff} instanceof state) {

                    $state = Factory::getComponent('shop')->getTable('state')->load_by_code($oshipping->{$ff}->get_country(), $oshipping->{$ff}->get_value());
                    if ($state->pk()) {
                        $formatted[] = strings::htmlentities($state->state_name);
                    } else if ($oshipping->{$ff}->get_value()) {
                        $formatted[] = strings::htmlentities($oshipping->{$ff}->get_value());
                    }
                } else {
                    $formatted[] = strings::htmlentities($oshipping->{$ff}->get_value());
                }
            }
        }

        if (empty($formatted))
            return '';
        $ls = apply_filters('address_format_line_separator', "<br />");
        return implode($ls, $formatted);
    }

    public function format_billing(array $data) {

        $obilling = $this->get_billing_fields();

        while ($f = $obilling->get_field()) {
            if (isset($data[$f->get_name()])) {
                $f->set_value($data[$f->get_name()]);
            }
        }

        if (isset($obilling->billing_country) && isset($obilling->billing_state)) {
            $obilling->billing_state->set_country($obilling->billing_country->get_value());
        }

        $format = apply_filters('billing_format', array(
            'billing_first_name',
            'billing_last_name',
            'billing_country',
            'billing_state',
            'billing_address',
            'billing_city',
            'billing_email',
            'billing_phone'
                )
        );

        $formatted = array();

        foreach ((array) $format as $ff) {

            if (isset($obilling->{$ff})) {
                if ($obilling->{$ff} instanceof country) {

                    $country = Factory::getComponent('shop')->getTable('country')->load_by_code($obilling->{$ff}->get_value());
                    $formatted[] = strings::htmlentities($country->country_name);
                } elseif ($obilling->{$ff} instanceof state) {

                    $state = Factory::getComponent('shop')->getTable('state')->load_by_code($obilling->{$ff}->get_country(), $obilling->{$ff}->get_value());
                    if ($state->pk()) {
                        $formatted[] = strings::htmlentities($state->state_name);
                    } else if ($obilling->{$ff}->get_value()) {
                        $formatted[] = strings::htmlentities($obilling->{$ff}->get_value());
                    }
                } else {
                    $formatted[] = strings::htmlentities($obilling->{$ff}->get_value());
                }
            }
        }

        if (empty($formatted))
            return '';

        $ls = apply_filters('address_format_line_separator', "<br />");

        return implode($ls, $formatted);
    }

}
