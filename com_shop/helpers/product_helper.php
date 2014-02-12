<?php

class product_helper {

    public function getInstance() {

        static $instance = null;

        if (!($instance instanceof self)) {

            $instance = new self;
        }

        return $instance;
    }

    protected function __construct() {
        
    }

    public function get_price_with_tax($o, $p = array(), $f = array(), $country = null, $state = null) {
		
        if (is_int($o) || is_string($o)) {
            $r = Factory::getApplication('shop')->getTable('product')->load($o);

            if (!$r->pk()) {

                throw new Exception('no such product');
            }
        } else if (is_object($o)) {

            $r = $o;
        } else {

            throw new Exception('no such product');
        }






        $params = Factory::getApplication('shop')->getParams();




        $helper = Factory::getApplication('shop')->getHelper('price');
        $tax = Factory::getApplication('shop')->getHelper('tax');
        $price = $this->get_price($o, $p, $f)->raw;
        $raw_price = $price;
        $tax_rate = 0;

        if (( $params->get('vat') && $r->vat == 'global' ) || $r->vat == 'yes') {





            if ($r->tax_group_id) {
                $tax_rate = $tax->get_tax_rate($country, $state, $r->tax_group_id);
                $price += $price * $tax_rate;
            } else {
                $tax_rate = $tax->get_tax_rate($country, $state);
               
                $price += $price * $tax_rate;
            }
        }

        $ret = new stdClass();
        $ret->price_formated = $helper->format($price);
        $ret->price = $price;
        $ret->tax = $tax_rate * 100;
        $ret->raw_price = $raw_price;

        return $ret;
    }

    public function get_price($o, $p = array(), $f = array()) {


        if (is_int($o) || is_string($o)) {
            
            $r = Factory::getApplication('shop')->getTable('product')->load($o);
          
            if (!$r->pk()) {
                throw new Exception(__('no such product', 'com_shop'));
            }
        } else if (is_object($o)) {
           
            $r = $o;
        } else {
          
            throw new Exception(__('no such product', 'com_shop'));
        }

        $price = $r->price;

        if (strtotime($r->discount_start) < time() && strtotime($r->discount_end) > time()) {
            $price = $r->discount_price;
        }

        $db = Factory::getDBO();

        if (!empty($p)) {
            $db = Factory::getDBO();
            $p = (array) array_map('intval', (array) array_unique((array) $p));
            $s = (array) array_map('intval', (array) array_unique((array) $s));

            $props = array();

            $db->setQuery("SELECT p.property_id,p.property_price,p.oprand FROM `#_shop_attribute_property` AS p

	INNER JOIN 	(
	(SELECT att.* FROM `#_shop_attribute` as att
	INNER JOIN `#_shop_product_attribsets` as xref ON xref.sid = att.attribute_set_id
	INNER JOIN `#_shop_attribute_set` as aset ON aset.attribute_set_id = xref.sid
	WHERE xref.pid = " . $r->id . " AND aset.published = 'yes' ) UNION
	(SELECT * FROM `#_shop_attribute` WHERE product_id = " . $r->id . " )
	)
	as a ON p.attribute_id = a.attribute_id

	WHERE p.property_id IN(" . implode(',', $p) . ") ");


            $tmp = array();

            while ($o = $db->nextObject()) {

                $tmp[] = $o->property_id;

                switch ($o->oprand) {

                    case "+":

                        $price += $o->property_price;

                        break;

                    case "-":

                        $price -= $o->property_price;

                        break;
                }
            }
         
        }

        if ((!empty($f) || !$r->download_choose_file ) && has_term('digital', 'product_type', (int) $r->pk())) {
            $f = array_map('intval', $f);

            $w = '';

            if ($r->download_choose_file) {
                $w = " AND meta.post_id IN (" . implode(',', $f) . ") ";
            }

            $db->setQuery("SELECT meta.meta_value as price FROM #_postmeta as meta
                    INNER JOIN #_posts as post ON post.ID = meta.post_id
                    WHERE meta.meta_key = '_price' AND post.post_parent = " . (int) $r->pk() . $w);


            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            while ($row = $db->nextObject()) {
                $price += (double) $row->price;
            }
        }
		// enable plugins to alter the product price
		$price = apply_filters( "orillacart_raw_price", $price,$o );

        $ret = new stdClass();
        $ret->raw = $price;
        $ret->formated = Factory::getApplication('shop')->getHelper('price')->format($price);

        return $ret;
    }

}