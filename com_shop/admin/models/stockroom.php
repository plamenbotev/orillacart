<?php

defined('_VALID_EXEC') or die('access denied');

class stockroomModel extends model {

    const table = '#_shop_stockroom';

    public function is_stockroom($id) {

        $this->db->setQuery("SELECT count(*) FROM " . self::table . " WHERE id = " . (int) $id);

        return (int) $this->db->loadResult();
    }

    public function listStockRooms() {


        $opts = new stdClass();

        $opts->start = 0;
        $opts->limit = 20;
        $opts->order_by = 'name';
        $opts->order_dir = 'ASC';

        if (func_num_args()) {
            $arg = func_get_arg(0);

            if (is_array($arg) || is_object($arg)) {

                foreach ($arg as $k => $v) {

                    if (property_exists($opts, $k))
                        $opts->$k = $v;
                }
            }
        }

        $this->db->setQuery("SELECT * FROM `" . self::table . "` WHERE 1 ORDER BY {$opts->order_by} {$opts->order_dir} LIMIT {$opts->start},{$opts->limit}");



        return $this->db;
    }

    public function getAllStockRooms() {


        $this->db->setQuery("SELECT * FROM `" . self::table . "` WHERE published = 'yes' ORDER BY name ASC");

        return clone $this->db;
    }

    public function getObjects() {


        $joins = array();
        $where = array();
        $from = '';
        $order_by = '';
        $limit = '';
        $group_by = '';
        $id = request::getInt('sr_id', null, 'POST');
        $type = request::getWord('stockroom_type', 'product', 'POST');
        $parent_product = request::getInt('parent_product', 0);


        $keyword = request::getWord('keyword', null, 'POST');

        $keyword = $this->db->secure($keyword);
        $filter = request::getVar('filter', array(), 'array');
        $cats = ArrayHelper::toInt($filter['cats']);



        $recursive = request::getBool('recursive', false, 'POST');

        $start = request::getInt('limitstart', 0, 'POST');
        $offset = request::getInt('limit', 10, 'POST');


        $limit = "LIMIT {$start},{$offset}";

        if ($id && !$this->is_stockroom($id))
            $id = null;


        switch ($type) {


            case "product_attribute_property":

                $select[] = 'SQL_CALC_FOUND_ROWS product.ID as product_id,product.post_title AS product_name, a.attribute_name as attribute_name, (SELECT meta_value FROM #_postmeta WHERE post_id = product.ID AND meta_key = "_sku" LIMIT 1 ) as sku';
                $select[] = 'sr.name AS sr_name, sr.id AS stockroom_id, p.property_name, p.property_id, s.stock';
                $from = '`#_shop_attribute_property` AS p';
                $where[] = '1';
                $joins[] = 'INNER JOIN `#_shop_attribute` AS a ON p.attribute_id = a.attribute_id';

                if ($id) {
                    $joins[] = 'INNER JOIN `#_shop_stockroom` AS sr ON sr.id = ' . $id;
                } else {
                    $joins[] = 'INNER JOIN `#_shop_stockroom` AS sr';
                }
                $joins[] = 'LEFT JOIN `#_shop_property_stockroom_xref` AS s ON s.property_id = p.property_id AND s.stockroom_id = sr.id';


                if ($parent_product) {
                    if (empty($cats)) {
                        $joins[] = 'INNER JOIN `#_posts` AS product ON a.product_id = product.ID AND product.post_type = "product"';
                    }
                    $where[] = " AND product.post_parent = " . $parent_product;
                }

                if ($keyword)
                    $where[] = "AND (p.property_name LIKE '{$keyword}%' OR a.attribute_name LIKE '{$keyword}%')";

                if (!empty($cats)) {
                    $joins[] = 'INNER JOIN `#_posts` AS product ON a.product_id = product.ID AND product.post_type = "product"';

                    $joins[] = 'INNER JOIN `#_term_relationships` AS productc ON productc.object_id = product.ID';
                    $joins[] = 'INNER JOIN #_term_taxonomy as tt ON tt.term_taxonomy_id = productc.term_taxonomy_id';

                    if ($recursive) {

                        $joins[] = " INNER JOIN `#_shop_category_xref` as recp ON recp.category_child_id IN(" . implode(',', $cats) . ")";
                        $joins[] = " INNER JOIN `#_shop_category_xref` as recc ON recc.a11 * recp.a21 >= recc.a21 * recp.a11 AND recc.a11 * recp.a22  < recc.a21 * recp.a12";
                        $where[] = "AND tt.term_id = recc.category_child_id ";
                    } else {


                        $where[] = ' AND tt.term_id IN(' . implode(',', $cats) . ')';
                    }

                    $group_by = 'GROUP BY product.ID,sr.id';
                } else {
                    $joins[] = 'LEFT JOIN `#_posts` AS product ON a.product_id = product.ID';
                }


                $order_by = "ORDER BY p.property_name ASC , p.property_id ASC , sr.name ASC";

                break;

            case 'product':
            default:


                $select[] = 'SQL_CALC_FOUND_ROWS b.post_title,b.ID as product_id,(SELECT meta_value FROM #_postmeta WHERE post_id = b.ID AND meta_key = "_sku" LIMIT 1 ) as sku, a.stock,sr.name as sr_name,sr.id as stockroom_id';

                $from = '`#_posts` as b';

                if ($id) {
                    $joins[] = 'INNER JOIN `#_shop_stockroom` AS sr ON sr.id = ' . $id;
                    $order_by = 'ORDER BY b.post_title ASC';
                } else {
                    $joins[] = 'INNER JOIN `#_shop_stockroom` AS sr';
                    $order_by = 'ORDER BY b.post_title ASC, sr.name ASC';
                }
                $joins[] = 'LEFT JOIN `#_shop_products_stockroom_xref` as a ON a.product_id = b.ID AND a.stockroom_id = sr.id';
                $where[] = '1';


                if ($keyword)
                    $where[] = "AND (b.post_title LIKE '{$keyword}%' OR (SELECT meta_value FROM #_postmeta WHERE post_id = b.ID AND meta_key = '_sku' LIMIT 1 ) LIKE '{$keyword}%')";

                if ($parent_product) {

                    $where[] = " AND b.post_parent = " . $parent_product;
                }


                if (!empty($cats)) {

                    $joins[] = 'INNER JOIN `#_term_relationships` AS productc ON productc.object_id = b.ID';
                    $joins[] = 'INNER JOIN #_term_taxonomy as tt ON tt.term_taxonomy_id = productc.term_taxonomy_id';

                    if ($recursive) {

                        $joins[] = " INNER JOIN `#_shop_category_xref` as recp ON recp.category_child_id IN(" . implode(',', $cats) . ")";
                        $joins[] = " INNER JOIN `#_shop_category_xref` as recc ON recc.a11 * recp.a21 >= recc.a21 * recp.a11 AND recc.a11 * recp.a22  < recc.a21 * recp.a12";
                        $where[] = "AND tt.term_id = recc.category_child_id ";
                    } else {


                        $where[] = ' AND tt.term_id IN(' . implode(',', $cats) . ')';
                    }

                    $group_by = 'GROUP BY b.ID,sr.id';
                }

                $where[] = " AND b.post_type='product' AND post_status ='publish' ";



                break;
        }


        $this->db->setQuery("SELECT " . implode(',', (array) $select) . " FROM " . $from . " " .
                implode("\n", (array) $joins) . " WHERE " .
                implode(" ", (array) $where) . " " . $group_by . " " . $order_by . " " . $limit);

      
        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }

        return clone $this->db;
    }

    public function changeState($id) {

        if (!$this->is_stockRoom($id))
            return false;

        $row = $this->loadStockRoom($id);

        if ($row->published == 'yes')
            $pub = 'no';
        else
            $pub = 'yes';


        $this->db->setQuery("UPDATE `" . self::table . "` SET published='" . $pub . "' WHERE id = {$row->id} LIMIT 1");
    }

    public function loadStockRoom($id) {

        if (!$this->is_StockRoom($id))
            return false;

        return Factory::getApplication('shop')->getTable('stockroom')->load($id);
    }

    public function delete($ids) {

        if (empty($ids))
            return false;

        $this->db->setQuery("DELETE FROM `" . self::table . "` WHERE id IN(" . implode(',', (array) $ids) . ") ");

        if ($this->db->getResource())
            return true;

        return false;
    }

    public function updateStocks() {


        $values = array();

        $sid = (array) array_map('intval', request::getVar('sid', array(), 'POST', 'array'));
        $pid = (array) array_map('intval', request::getVar('pid', array(), 'POST', 'array'));
        $amounts = (array) array_map('intval', request::getVar('quantity', array(), 'POST', 'array'));

        if (empty($sid))
            return false;

        foreach ((array) $pid as $k => $v) {



            $values[] = "('{$v}','" . $sid[$k] . "','" . $amounts[$k] . "')";
        }
        unset($sid, $pid, $amounts);


        $table = null;
        switch ($_POST['stockroom_type']) {

            case 'product':

                $table = '`#_shop_products_stockroom_xref`';

                break;

            case 'product_attribute_property':

                $table = '`#_shop_property_stockroom_xref`';

                break;
            default:

                throw new Exception(__('unknown stock room type!', 'com_shop'));

                break;
        }

        $this->db->setQuery("REPLACE INTO {$table} VALUES" . implode(',', $values));

        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }
    }

}