<?php

abstract class payment_method implements SelfRegisterable {

    const pod = 1;
    const ccard = 2;
    const form = 3;

    protected $active = false;
    protected $name = '';
    protected $id = null;
    protected $params = null;

    public function __construct() {

        $db = Factory::getDBO();

        $db->setQuery("SELECT * FROM #_shop_methods WHERE type='payment' AND class = '" . $db->secure($this->get_class_name()) . "' LIMIT 1");

        if (!$db->getResource()) {

            throw new Exception($db->getErrorString());
        }
        $row = $db->nextObject();

        if ($row) {
            $this->active = true;
            $this->name = $row->name;
            $this->id = (int) $row->method_id;
            $this->params = new Registry($row->params);
        } else {
            $this->params = new Registry();
        }
    }

    abstract public function do_payment($order_id, $order);

    public function fields() {
        return '';
    }

    final public function get_class_name() {

        return get_class($this);
    }

    final public function get_type() {

        if ($this instanceof card_method)
            return self::ccard;
        if ($this instanceof form_method)
            return self::form;
        if ($this instanceof pod_method)
            return self::pod;

        throw new Exception(__("Unknown payment gateway type!", "com_shop"));
    }

    public function is_active() {
        return (bool) $this->active;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_id() {
        return $this->id;
    }

    abstract public function print_options();



    public function on_receipt($order = null) {
        return "";
    }

    public function change_order_state($state, $order_id, $order) {
        return true;
    }

    public function save_options($id) {

        $input = Factory::getApplication()->getInput();

        $params = $input->get('params', array(), "ARRAY");
        $gw = Table::getInstance('payment', 'shop')->load($id);
        $gw->params = $params;
        $gw->store();
    }

}

abstract class card_method extends payment_method {

    static private $cards = array();
    protected $require_cvv = false;
    protected $require_ctype = false;
    private $scards = array();

    final public function add_card_support($type, $o = null) {
        if (isset(self::$cards[$type])) {

            if (is_object($o) && $o instanceof pay_card) {
                $this->scards[$type] = $o;
            } else {
                $this->scards[$type] = self::$cards[$type];
            }
        } else if (is_object($o) && $o instanceof pay_card) {
            $this->scards[$type] = $o;
        } else {
            throw new Exception(__("card definition is missing!", "com_shop"));
        }
    }

    public function __construct() {

        self::$cards['amex'] = new pay_card('American Express', 'Amex', '/^3[4,7]\d{13}$/', 4);
        self::$cards['dc'] = new pay_card("Diner's Club", 'DC', '/^(30|36|38|39|54)\d{12}$/', 3);
        self::$cards['disc'] = new pay_card("Discover Card", 'Disc', '/^6(011|22[0-9]|4[4-9]0|5[0-9][0-9])\d{12}$/', 3);
        self::$cards['jcb'] = new pay_card('JCB', 'JCB', '/^35(2[8-9]|[3-8][0-9])\d{12}$/', 3);
        self::$cards['lasr'] = new pay_card('Laser', 'Lasr', '/^(6304|6706|6709|6771)\d{12,15}$/');
        self::$cards['maes'] = new pay_card('Maestro', 'Maes', '/^(311|367|[5-6][0-9][0-9][0-9])\d{8,15}$/', 3, array('start' => 5, 'issue' => 3));
        self::$cards['mc'] = new pay_card('MasterCard', 'MC', '/^5(1|5)\d{14}$/', 3);
        self::$cards['solo'] = new pay_card('Solo', 'Solo', '/^(6334|6767)(\d{12}|\d{14,15})$/', 3, array('start' => 5, 'issue' => 3));
        self::$cards['visa'] = new pay_card('Visa', 'Visa', '/^4\d{15}$/', 3);

        self::$cards = (array) apply_filters('com_shop_card_definitions', self::$cards);

        foreach ((array) self::$cards as $k => $card) {
            if (!( $card instanceof pay_card ))
                unset(self::$cards[$k]);
        }




        parent::__construct();
    }

    final public function get_cards_list() {

        return $this->scards;
    }

    final public function get_card($type) {
        if (isset($this->scards[$type])) {
            return $this->scards[$type];
        }

        return null;
    }

    final public function require_cvv() {
        return (bool) $this->require_cvv;
    }

    final public function require_ctype() {
        return (bool) $this->require_ctype;
    }

}

abstract class form_method extends payment_method {

    abstract public function handle_notify();
}

abstract class pod_method extends payment_method {
    
}

class pay_card {

    protected $name;
    protected $symbol;
    protected $pattern = false;
    protected $csc = false;
    protected $inputs = array();

    public function get_name() {
        return $this->name;
    }

    public function get_symbol() {
        return strtolower($this->symbol);
    }

    public function get_pattern() {
        return $this->pattern;
    }

    public function get_csc() {
        return $this->csc;
    }

    public function get_input() {
        return $this->input;
    }

    public function __construct($name, $symbol, $pattern, $csc = false, $inputs = array()) {
        $this->name = $name;
        $this->symbol = $symbol;
        $this->pattern = $pattern;
        $this->csc = $csc;
        $this->inputs = $inputs;
    }

    public function validate($pan) {
        $n = preg_replace('/\D/', '', $pan);
        return ($this->match($n) && $this->checksum($n));
    }

    public function match($number) {
        if ($this->pattern && !preg_match($this->pattern, $number))
            return false;
        return true;
    }

    public function checksum($number) {
        $code = strrev($number);
        for ($i = 0; $i < strlen($code); $i++) {
            $d = intval($code[$i]);
            if ($i & 1)
                $d *= 2;
            $cs += $d % 10;
            if ($d > 9)
                $cs += 1;
        }
        return ($cs % 10 == 0);
    }

}
