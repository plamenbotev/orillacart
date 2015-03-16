<?php

class paymentTable extends table {

    public $method_id = null;
    public $name = null;
    public $method_order = 0;
    public $class = null;
    public $type = 'payment';
    public $countries = null;
    public $params = null;

    public function __construct() {
        parent::__construct('method_id', '#_shop_methods');
    }

    public function load($id=null) {

        parent::load($id);

        if (!$this->pk()) {

            $db = Factory::getDBO();
            $db->setQuery("SELECT MAX(method_order)+1 FROM #_shop_methods WHERE type='payment'");
            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }
            $this->method_order = (int) $db->loadResult();
        }

        if ($this->countries) {
            $this->countries = explode(',', $this->countries);
        } else {
            $this->countries = array();
        }

        $this->params = new Registry($this->params);

        return $this;
    }

    public function store($safe_insert = false) {

        if (empty($this->name)) {
            Factory::getComponent('shop')->addError(__('Enter method label', 'com_shop'));
            return false;
        }

        if (!empty($this->countries)) {
            $this->countries = implode(',', $this->countries);
        } else {
            $this->countries = '';
        }



        if ($this->params instanceof registry) {
            $this->params = $this->params->toString();
        } else {
            $this->params = new Registry($this->params);
            $this->params = $this->params->toString();
        }




        return parent::store($safe_insert);
    }

}
