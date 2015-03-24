<?php

class carrierTable extends table {

    public $method_id = null;
    public $name = null;
    public $method_order = 0;
    public $class = null;
    public $type = 'shipping';
    public $params = null;

    public function __construct() {

        parent::__construct('method_id', '#_shop_methods');
    }

    public function load($id = NULL) {

        parent::load($id);

        if (empty($this->class))
            $this->class = 'standart_shipping';

        if (!$this->pk()) {

            $db = Factory::getDBO();
            $db->setQuery("SELECT MAX(method_order)+1 FROM #_shop_methods WHERE type='shipping'");
            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }
            $this->method_order = (int) $db->loadResult();
        }

        $this->params = new Registry($this->params);



        return $this;
    }

    public function store($safe_insert = false) {

        if ($this->class == 'standart_shipping')
            $this->class = null;

        if (empty($this->name)) {
            Factory::getComponent('shop')->addError(__('Enter carrier label', 'com_shop'));
			
			if ($this->class == null){
				$this->class =  'standart_shipping';
			}
			
            return false;
        }



        if ($this->params instanceof registry) {
            $this->params = $this->params->toString();
        } else {
            $this->params = new Registry($this->params);
            $this->params = $this->params->toString();
        }
       
		$res = parent::store();
		
		if ($this->class == null){
			$this->class =  'standart_shipping';
		}
		
		return $res;
    }

}
