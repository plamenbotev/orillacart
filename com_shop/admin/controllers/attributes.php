<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerAttributes extends controller {

    protected function __default() {

        $this->getView('attributes');

        $model = $this->getModel('attributes');

        $this->view->assign('att_sets', $model->getAllAttributeSets());

        parent::display();
    }

    protected function changestate() {

        $model = $this->getModel('attributes');

        $id = (int) $_POST['id'];

        $ret = new stdClass();

        $ret->status = 1;
        $ret->row = $model->changeState($id);

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($ret);
        die();
    }

    protected function delete_sets() {

        $post = Factory::getApplication()->getInput()->post;

        $sets = (array) array_map('intval', (array) $post['ids']);

        $model = $this->getModel('attributes');
        $this->getView('attributes');

        $db = Factory::getDBO();
        $db->startTransaction();
        $db->setQuery("SELECT attribute_id FROM `#_shop_attribute` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");

        if ($db->numRows() > 0) {

            try {

                $model->delete((array) $db->loadArray());
            } catch (Exception $e) {
                $db->rollback();
                Factory::getComponent('shop')->setMessage(__("error deleteing", 'com_shop'));
                $this->execute();

                return false;
            }


            $db->setQuery("DELETE FROM  `#_shop_attribute` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");
            if (!$db->getResource()) {

                $db->rollback();
                Factory::getComponent('shop')->setMessage(__("error deleteing", 'com_shop'));
                $this->execute();
                return false;
            }
        }


        $db->setQuery("DELETE FROM `#_shop_attribute_set` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");

        if (!$db->getResource()) {
            $db->rollback();
            Factory::getComponent('shop')->setMessage(__("error deleteing", 'com_shop'));
            $this->execute();
            return false;
        }

        $db->commit();

        Factory::getComponent('shop')->setMessage(__("success", 'com_shop'));

        $this->execute();
        return true;
    }

    protected function save() {

        $model = $this->getModel('attributes');
        $this->getView('attributes');

        try {
            $model->store();

            Factory::getComponent('shop')->setMessage(__("Set saved", 'com_shop'));
        } catch (Exception $e) {
            Factory::getComponent('shop')->setMessage($e->getMessage());
			return $this->execute("edit");
        }

        $this->execute();
    }

    protected function edit() {

        $stock_model = $this->getModel('stockroom');
		$input = Factory::getApplication()->getInput();
        $set_id = (int) $input->get("id",$input->get("attribute_set_id",0,"INT"),"INT");
		
		

        $model = $this->getModel('attributes');
        $this->getView('attributes');

        $this->view->assign('stock_rooms', $stock_model->getAllStockRooms());
		$set  = null;
        if ($model->is_set($set_id)) {
            $set = $model->getattributes($set_id);
			

            
            
        } else {
          
			
			$set = new stdClass();
			
			$set->attribute_set_id = 0;
			$set->published = "no";
			$set->attribute_set_name = "";
			$set->_data = array();
			$set->_meta = new stdClass();
			$set->_meta->total_attributes = 0;
			$set->_meta->total_properties = 0;
			
			
			
			
			
		}
		
		//Preserve the edited attributes on error.
		if($input->get("task",null,"CMD") == "save"){
				
			$set->published = $input->get("published","no","WORD");
			$set->attribute_set_name = $input->get("attribute_set_name","","STRING");
			$set->_data = array();
			
			$atts = $input->get("attribute_id",array(),"ARRAY");
			
			foreach((array)$atts as $k => $id){
				
				$set->_data[$k] = array();
				
				$set->_data[$k]['att'] = new stdClass();
				
				$set->_data[$k]['att']->attribute_id = (int)$id;
				$set->_data[$k]['att']->attribute_name = isset($input->request["title"][$k]['name']) ? $input->request["title"][$k]['name'] : "";
				$set->_data[$k]['att']->attribute_required = !empty($input->request["title"][$k]['required']) ? "yes" : "no";
				$set->_data[$k]['att']->hide_attribute_price = !empty($input->request["title"][$k]['hide_attribute_price']) ? "yes" : "no";
				$set->_data[$k]['att']->product_id=0;
				$set->_data[$k]['att']->ordering = !empty($input->request["title"][$k]['ordering']) ? $input->request["title"][$k]['ordering'] : $k;
				$set->_data[$k]['att']->product_id = $set->attribute_set_id;
							
				
				$set->_data[$k]['property'] = array();
				if(isset($input->request['property_id'][$k]['value'])){
					
					$props = (array)$input->request['property_id'][$k]['value'];
					
					
					foreach((array)$props as $kp => $pid){
						
						$set->_data[$k]['property'][$kp] = new stdClass();
						
						$set->_data[$k]['property'][$kp]->property_id = (int)$pid;
						$set->_data[$k]['property'][$kp]->attribute_id = $set->_data[$k]['att']->attribute_id;
						
						$set->_data[$k]['property'][$kp]->property_name = "";
						if(isset($input->request['property'][$k]['value'][$kp])){
							$set->_data[$k]['property'][$kp]->property_name = $input->request['property'][$k]['value'][$kp];
						}
						
						$set->_data[$k]['property'][$kp]->property_price = 0;
						if(isset($input->request['att_price'][$k]['value'][$kp])){
							$set->_data[$k]['property'][$kp]->property_price = $input->request['att_price'][$k]['value'][$kp];
						}
						
						$set->_data[$k]['property'][$kp]->oprand = "+";
						if(isset($input->request['oprand'][$k]['value'][$kp])){
							$set->_data[$k]['property'][$kp]->oprand = $input->request['oprand'][$k]['value'][$kp];
						}
						
						$set->_data[$k]['property'][$kp]->ordering = $kp;
						if(isset($input->request['propordering'][$k]['value'][$kp])){
							$set->_data[$k]['property'][$kp]->ordering = $input->request['propordering'][$k]['value'][$kp];
						}
						
					}
				}
			}
		}
			
		$this->view->assign("set",$set);
		
		parent::display('edit_set');
		
    }



    protected function save_stock() {

        $model = $this->getModel('attributes');

        $input = Factory::getApplication()->getInput();

        $el_id = $input->get("id", 0, "INT");
        $el_type = $input->get("object", "", "STRING");
        $values = (array) array_map('intval', (array) $input->get('values', array(), "ARRAY"));


        $result = new stdClass();
        $result->status = null;
        $result->msg = null;


        try {
            $result->status = $model->updateStockAndDeleteDiff($el_id, $values);

            $result->status = true;
            $result->msg = __("Stockroom saved", 'com_shop');
        } catch (Exception $e) {

            $result->status = false;
            $result->msg = $e->getMessage();
        }

        ob_end_clean();


        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($result);
        die();
    }

    protected function get_stock() {

        $type = (string) $_GET['type'];
        $id = (int) $_GET['id'];

        $result = array();

        $model = $this->getModel('attributes');

        try {
            $result = $model->getStocks($id);
        } catch (Exception $e) {
            header("HTTP/1.0 500 INTERNAL SERVER ERROR");
            header('Content-type: text/json; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");

            echo strings::htmlentities($e->getMessage());
            die();
        }

        ob_end_clean();

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($result);
        die();
    }

}
