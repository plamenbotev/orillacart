<?php

defined('_VALID_EXEC') or die('access denied');

class attributesModel extends model {

    public function is_set($id) {

        static $cache = array();

        $id = (int) $id;

        if (array_key_exists($id, $cache))
            return $cache[$id];


        $this->db->setQuery("SELECT count(*) FROM `#_shop_attribute_set` WHERE attribute_set_id = {$id} ");

        $cache[$id] = $this->db->loadResult();

        return $cache[$id];
    }

    public function is_product($id) {

        static $cache = array();

        $id = (int) $id;

        if (array_key_exists($id, $cache))
            return $cache[$id];


        $this->db->setQuery("SELECT count(*) FROM `#_shop_attribute` WHERE product_id = {$id} ");

        $cache[$id] = $this->db->loadResult();

        return $cache[$id];
    }

    public function is_attribute($id) {

        static $cache = array();

        $id = (int) $id;

        if (array_key_exists($id, $cache))
            return $cache[$id];


        $this->db->setQuery("SELECT count(*) FROM `#_shop_attribute` WHERE attribute_id = {$id} ");

        $cache[$id] = $this->db->loadResult();

        return $cache[$id];
    }

    public function is_property($id) {

        static $cache = array();

        $id = (int) $id;

        if (array_key_exists($id, $cache))
            return $cache[$id];


        $this->db->setQuery("SELECT count(*) FROM `#_shop_attribute_property` WHERE property_id = {$id} ");

        $cache[$id] = $this->db->loadResult();

        return $cache[$id];
    }

    public function changeState($id) {



        $row = Factory::getComponent('shop')->getTable('shop_attribute_set')->load($id);


        if ($row->published == 'yes')
            $row->published = 'no';
        else
            $row->published = 'yes';

        return $row->store();
    }

    public function getAllAttributeSets() {


        $this->db->setQuery("SELECT * FROM `#_shop_attribute_set` WHERE 1 ");

        return clone $this->db;
    }

    public function getStocks($id) {
        $id = (int) $id;


        if (!$this->is_property($id))
            throw new Exception("invalid property:" . $id);

        $this->db->setQuery("	SELECT a.*, b.name as stockroom_name FROM `#_shop_property_stockroom_xref` AS a
								INNER JOIN `#_shop_stockroom` AS b ON a.stockroom_id = b.id
								WHERE property_id = {$id} ");


        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString);
        }

        return (array) $this->db->loadObjectList();
    }

    function getattributes($set_id, $object = 'set') {

        if (!in_array($object, array('set', 'product')))
            throw new Exception(__("Unknown object", 'com_shop'));

        $set = new stdClass();

        if ($object == 'set') {
            if (!$this->is_set($set_id))
                return false;
            $set = Factory::getComponent('shop')->getTable('shop_attribute_set')->load($set_id);

            $this->db->setQuery("SELECT * FROM `#_shop_attribute` WHERE attribute_set_id = {$set_id} ORDER BY ordering");
        }

        else {
            if (!$this->is_product($set_id))
                return false;
            $this->db->setQuery("SELECT * FROM `#_shop_attribute` WHERE product_id = {$set_id} ORDER BY ordering");

            if (!$this->db->getResource()) {

                throw new Exception($this->db->getErrorString());
            }
        }



        $att_data = array();
        $c = 0;
        $info = new stdClass();
        $info->total_attributes = 0;
        $info->total_properties = 0;



        while ($att = $this->db->nextObject()) {

            $info->total_attributes++;


            $att_data[$c] = array('att' => null, 'property' => array());
            $att_data[$c]['att'] = $att;



            $prop_db = Factory::getDBO();

            $prop_db->setQuery("SELECT * FROM `#_shop_attribute_property` WHERE attribute_id = {$att->attribute_id} ORDER BY ordering");


            while ($property = $prop_db->nextObject()) {
                $info->total_properties++;
                $att_data[$c]['property'][] = $property;
            }


            $c++;
        }
        $set->_data = $att_data;
        $set->_meta = $info;

		


        return $set;
    }

    public function updateStockAndDeleteDiff($id, array $values) {

        if (!empty($values)) {
            $this->db->setQuery("SELECT stockroom_id FROM `#_shop_property_stockroom_xref` WHERE property_id = " . (int) $id);

            $for_delete = (array) array_diff((array) $this->db->loadArray(), (array) array_keys($values));

            $this->db->setQuery("DELETE FROM `#_shop_property_stockroom_xref` WHERE property_id = " . (int) $id . " AND stockroom_id IN(" . implode(',', $for_delete) . ")");

            $que = " REPLACE INTO `#_shop_property_stockroom_xref` ( `property_id`, `stockroom_id`, `stock`) VALUES ";
            $tmp = array();
            foreach ((array) $values as $sr => $q) {

                $tmp[] = " ('" . (int) $id . "', '" . (int) $sr . "', '" . (int) $q . "') ";
            }
            $que .= implode(',', $tmp);
            unset($tmp);

            $this->db->setQuery($que);

            if (!$this->db->getResource()) {

                throw new Exception($this->db->getErrorString());
            }

            return true;
        } else {

            $this->db->setQuery("DELETE FROM `#_shop_property_stockroom_xref` WHERE property_id = " . (int) $id);

            if (!$this->db->getResource()) {

                throw new Exception($this->db->getErrorString());
            }

            return true;
        }

        return false;
    }

    public function delete(array $ids, $object = 'attribute') {

        $ids = array_map("intval", (array) $ids);


        switch ($object) {


            case 'attribute': {


                    $this->db->setQuery("DELETE FROM `#_shop_attribute` WHERE attribute_id IN(" . implode(',', $ids) . ")");


                    return true;
                }
                break;

            case 'property': {



                    $this->db->setQuery("DELETE FROM `#_shop_attribute_property` WHERE property_id IN(" . implode(',', $ids) . ")");


                    return true;
                }
                break;

            default:
                return false;
                break;
        }


        return false;
    }

    public function store() {

        $input = Factory::getApplication()->getInput();

        $post = $input->post;


        if ($post['attribute_set_name']) {
            if ((int) $post['attribute_set_id'] && $this->is_set((int) $post['attribute_set_id'])) {
                $objects = array();

                foreach ((array) $post['attribute_id'] as $ak => $att) {
                    if ($att['id'])
                        $objects[] = (int) $att['id'];
                }

                $this->db->setQuery('SELECT attribute_id FROM `#_shop_attribute` WHERE attribute_set_id = ' . (int) $post['attribute_set_id']);

                $for_delete = (array) array_diff((array) $this->db->loadArray(), (array) $objects);

                if (!empty($for_delete)) {
                    $this->delete($for_delete, 'attribute');
                }
                unset($for_delete);
            }

            $props = array();


            $set = null;
            $set = Factory::getComponent('shop')->getTable('shop_attribute_set')->load((int) $post['attribute_set_id'])->bind($post)->store();

            if ($set->pk()) {

                if (!isset($post['attribute_id']) || empty($post['attribute_id']))
                    return;

                foreach ((array) $post['attribute_id'] as $ak => $att) {
                    $row = Factory::getComponent('shop')->getTable('shop_attribute');

                    if ($this->is_attribute($att['id'])) {
                        $row->load($att['id']);
                    }

                    if (isset($post['title'][$ak]['name']))
                        $row->attribute_name = $post['title'][$ak]['name'];

                    if (isset($post['title'][$ak]['required'])) {
                        $row->attribute_required = 'yes';
                    } else {
                        $row->attribute_required = 'no';
                    }


                    if (isset($post['title'][$ak]['ordering']))
                        $row->ordering = (int) $post['title'][$ak]['ordering'];

                    if (isset($post['title'][$ak]['hide_attribute_price'])) {
                        $row->hide_attribute_price = 'yes';
                    } else {
                        $row->hide_attribute_price = 'no';
                    }
                    $row->attribute_set_id = $set->pk();
                    $row->product_id = null;
                    $row->stockroom_id = null;
                    $row->store();

                    if (isset($post['property'][$ak]['value'])) {


                        foreach ((array) $post['property'][$ak]['value'] as $k => $title) {
                            $prop_row = Factory::getComponent('shop')->getTable('shop_attribute_property');

                            if ($this->is_property($post['property_id'][$ak]['value'][$k])) {
                                $prop_row->load($post['property_id'][$ak]['value'][$k]);
                                if ($prop_row->pk())
                                    $props[] = (int) $prop_row->pk();
                            }

                            $prop_row->attribute_id = $row->pk();

                            if (!empty($title))
                                $prop_row->property_name = $title;

                            $prop_row->property_price = (double) $post['att_price'][$ak]['value'][$k];

                            if (isset($post['oprand'][$ak]['value'][$k]) &&
                                    in_array($post['oprand'][$ak]['value'][$k], array('*', '-', '+', '/')))
                                $prop_row->oprand = $post['oprand'][$ak]['value'][$k];

                            $prop_row->ordering = (int) $post['propordering'][$ak]['value'][$k];

                            $prop_row->store();

                            if ($prop_row->pk())
                                $props[] = (int) $prop_row->pk();
                        }
                    }
                }

                $for_delete = array();

                if (!empty($objects)) {


                    $this->db->setQuery("SELECT property_id FROM `#_shop_attribute_property` WHERE attribute_id IN(" . implode(',', $objects) . ")");

                    if (!empty($props)) {
                        $for_delete = (array) array_diff((array) $this->db->loadArray(), (array) $props);
                    } else {

                        $for_delete = (array) $this->db->loadArray();
                    }
                }

                if (!empty($for_delete)) {

                    $this->delete($for_delete, 'property');
                }
            }

            return true;
        }else{
			throw new Exception(__("Please provide attribute set name.","com_shop"));
		}
    }

}
