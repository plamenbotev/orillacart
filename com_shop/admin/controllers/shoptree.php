<?php

defined('_VALID_EXEC') or die('access denied');

lib::import("tree");

class shopControllerShopTree extends controller {

    public function display() {


        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');


        if ($_REQUEST["operation"] && strpos("_", $_REQUEST["operation"]) !== 0 && method_exists($this, $_REQUEST["operation"])) {
            header("HTTP/1.0 200 OK");
            header('Content-type: text/json; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");
            echo $this->{$_REQUEST["operation"]}($_REQUEST);
            die();
        }
        header("HTTP/1.0 404 Not Found");
    }

    public function node_editor($data) {

        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');

        $model = $this->getModel('category');

        $id = $data['id'];

        if (!$id || !$model->is_cat($id)) {

            return "{ 'status':1, 'error':1, 'errormsg':'" . __("Invalid category", 'com_shop') . "'}";
        }

        $row = $model->loadCat($id);





        $ret = new stdClass();
        $ret->status = 1;
        $ret->error = 0;
        $ret->row = $row;

        return json_encode($ret);
    }

    public function create_node($data) {

        $res = new stdClass();


        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');


        $id = $jstree->_create($data);

        if (is_wp_error($id)) {
            $res->status = 0;
            $res->msg = $id->get_error_message();
            return json_encode($res);
        }

        if ($id) {
            $data["id"] = $id;
            $res->status = 1;
            $res->id = (int) $id;
            return json_encode($res);
        }
        $res->status = 0;
        return json_encode($res);
    }

    public function rename_node($data) {

        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');


        if (!$data['category_child_id'] || empty($data['title'])) {
            return "{ \"status\" : 0 }";
        }

        $jstree->rename($data['category_child_id'], $data['title']);

        return "{ \"status\" : 1 }";
    }

    public function move_node($data) {
        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');


        $jstree->moveCat($data);

        return "{ \"status\" : 1}";
    }

    public function remove_node($data) {

        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');


        $thumb_path = Factory::getApplication('shop')->getParams()->get('categoryThumbPath');
        $img_path = Factory::getApplication('shop')->getParams()->get('categoryImagePath');





        $id = $jstree->_remove((int) $data["category_child_id"]);

        if (!$id)
            return "{ \"status\" : 0 }";
        return "{ \"status\" : 1 }";
    }

    public function get_children($data) {

        $jstree = new treeBase("#_shop_category", "#_shop_category_xref", 'product_cat', 'product');


        $result = array();

        if ($data['category_child_id'] == -1) {

            $result[] = array(
                "attr" => array("id" => "node_0", "rel" => 'tree'),
                "data" => 'root',
                "state" => ($jstree->hasChildrens(0) > 0) ? "closed" : ""
            );
        } else {
            $tmp = $jstree->_get_children($data['category_child_id']);



            $product = $this->getModel('product_admin');
            $selected_cats = array();

            if (isset($data['pid']) && is_numeric($data['pid']) && $product->is_product($data['pid'])) {


                $selected_cats = $product->getProductCatIds($data['pid']);
            } else {

                $selected_cats = ArrayHelper::toInt($_SESSION['filter']['cats']);
            }

            $c = 0;

            foreach ($tmp as $k => $o) {

                if ($o->term_id == 0)
                    continue;

                $result[$c] = array(
                    "attr" => array("id" => "node_" . $o->term_id, "rel" => $o->type),
                    "data" => $o->name,
                    "state" => ($jstree->hasChildrens($o->term_id) > 0) ? "closed" : "",
                );

                if (!empty($selected_cats) && in_array($o->term_id, $selected_cats)) {

                    $result[$c]['attr']['class'] = 'checked';
                }



                $c++;
            }
        }
        return json_encode($result);
    }

    public function search($data) {


        /*
          $this->db->query("SELECT `".$this->fields["left"]."`, `".$this->fields["right"]."` FROM `".$this->table."` WHERE `".$this->fields["title"]."` LIKE '%".$this->db->escape($data["search_str"])."%'");
          if($this->db->nf() === 0) return "[]";
          $q = "SELECT DISTINCT `".$this->fields["id"]."` FROM `".$this->table."` WHERE 0 ";
          while($this->db->nextr()) {
          $q .= " OR (`".$this->fields["left"]."` < ".(int)$this->db->f(0)." AND `".$this->fields["right"]."` > ".(int)$this->db->f(1).") ";
          }
          $result = array();
          $this->db->query($q);
          while($this->db->nextr()) { $result[] = "#node_".$this->db->f(0); }
          return json_encode($result);

         */
    }

}