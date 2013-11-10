<?php

class product_adminModel extends model {

    protected $query = null;

    public function filter_post_by_title($where, &$wp_query) {


        global $wpdb;
        if ($post_title_like = $wp_query->get('filter_product_title')) {

            $where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'' . esc_sql(like_escape($post_title_like)) . '%\'';
        }
        return $where;
    }

    public function get_parent_list($str, $exclude = array()) {



        add_filter('posts_where', array($this, 'filter_post_by_title'), 10, 2);

        $res = array();
        $this->query = new WP_Query();
        $this->query->query(array('posts_per_page' => 10,
            'post_parent' => 0,
            'post_status' => 'publish',
            'post_type' => 'product',
            'post__not_in' => $exclude,
            'filter_product_title' => $str));

        while ($this->query->have_posts()) {
            $this->query->the_post();
            $res[get_the_ID()] = get_the_title();
        }

        wp_reset_postdata();
        return $res;
    }

    public function get_downloadable_files($id = null) {
        global $post;
        if (is_null($id)) {
            $id = (int) $post->ID;
        }
        if (!$this->is_product($id) || !has_term('digital', 'product_type', (int) $id))
            return array();
        $files = array();
        $image_query = new WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'post_parent' => (int) $id, 'orderby' => 'menu_order', 'order' => 'ASC', 'tax_query' =>
            array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => array('digital'),
                )
            )
        ));

        if ($image_query->have_posts())
            while ($image_query->have_posts()) {
                $image_query->the_post();

                $files[get_the_ID()] = get_the_title();
            }

        wp_reset_postdata();

        return $files;
    }

    public function getAttributeSets($pid) {
        $pid = (int) $pid;
        if (!$this->is_product($pid))
            return false;

        $this->db->setQuery("SELECT * FROM `#_shop_attribute_set` as a
                            INNER JOIN `#_shop_product_attribsets` as b ON a.attribute_set_id = b.sid
                            WHERE b.pid = {$pid} ");



        if (!$this->db->getResource()) {

            throw new Exception($this->db->getErrorString());
        }


        return clone $this->db;
    }

    public function is_product($id) {
        if (get_post_type((int) $id) == 'product')
            return true;
        return false;
    }

    public function load($id) {

        if ($this->is_product($id)) {

            $p = Factory::getApplication('shop')->getTable('product');


            return $p->load($id);
        }

        return false;
    }

    public function getProductCatIds($id) {


        return wp_get_post_terms((int) $id, 'product_cat', array("fields" => "ids"));
    }

    public function get_product_brands_ids($id) {

        return wp_get_post_terms((int) $id, 'product_brand', array("fields" => "ids"));
    }

    public function save() {



        $params = Factory::getApplication('shop')->getParams();

        $in_autosave = false;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX)) {

            $in_autosave = true;

            if (!is_numeric($_POST['menu_order']))
                unset($_POST['menu_order']);
            if (!is_numeric($_POST['price']))
                unset($_POST['price']);
        }



        $p = Factory::getApplication('shop')->getTable('product');

        if ($_POST['id']) {

            $p->load((int) $_POST['id']);
        }
        $p->bind($_POST);


        if (!$p->id)
            return false;

        if ($p->check()) {

            $p->store();

            if (!$p->pk()) {

                return false;
            }

            //disable saving of certain things if the current product is variation

            $is_variation = isset($p->post->post_parent) ? (bool) $p->post->post_parent : false;

            if (!$is_variation) {
                $sets = array();
                $sets = array_map('intval', (array) $_POST['attribute_bank_assoc']);

                $this->db->setQuery("SELECT attribute_set_id  FROM `#_shop_attribute_set` WHERE attribute_set_id IN(" . implode(',', $sets) . ")");

                $sets = (array) $this->db->loadArray();

                if (!empty($sets)) {

                    $for_remove = array();


                    $this->db->setQuery("SELECT sid FROM `#_shop_product_attribsets` WHERE pid = " . $p->pk());

                    $for_remove = (array) array_diff((array) $this->db->loadArray(), $sets);

                    if (!empty($for_remove)) {
                        $this->db->setQuery("DELETE FROM `#_shop_product_attribsets` WHERE pid = " . $p->pk() . " AND sid IN(" . implode(',', $for_remove) . ")");
                        unset($for_remove);
                    }




                    $q = array();
                    foreach ($sets as $sid) {

                        $q[] = "(NULL," . $p->pk() . "," . $sid . ")";
                    }

                    $q = "REPLACE INTO `#_shop_product_attribsets` (id,pid,sid) VALUES " . implode(',', $q);

                    $this->db->setQuery($q);
                    //unset($q);

                    if (!$this->db->getResource()) {

                        return false;
                    }
                } else if (!$in_autosave) {

                    $this->db->setQuery("DELETE FROM `#_shop_product_attribsets` WHERE pid = " . $p->pk());
                }

                if (!$in_autosave) {
                    try {

                        $this->saveProductAttributes($p->pk());
                    } catch (Exception $e) {


                        return false;
                    }
                }
            }



            if ($_POST['cats']) {

                $cats = array_map('intval', $_POST['cats']);
                $cats = array_unique($cats);

                wp_set_post_terms($p->pk(), $cats, 'product_cat');
            } else if (!$in_autosave) {

                wp_set_post_terms($p->pk(), array(), 'product_cat');
            }

            if ($_POST['brand']) {

                $brands = array_map('intval', $_POST['brand']);
                $brands = array_unique($brands);

                wp_set_post_terms($p->pk(), $brands, 'product_brand');
            } elseif (!$in_autosave) {

                wp_set_post_terms($p->pk(), array(), 'product_brand');
            }

            if ($_POST['shipping_group']) {

                if (is_array($_POST['shipping_group'])) {
                    $_POST['shipping_group'] = $_POST['shipping_group'][0];
                }
                $shipping_group = (int) $_POST['shipping_group'];


                wp_set_post_terms($p->pk(), array($shipping_group), 'shipping_group');
            } elseif (!$in_autosave) {
                wp_set_post_terms($p->pk(), array(), 'shipping_group');
            }

            if (!$in_autosave) {
                $product_type = Request::getString('type', 'regular');
                if (!in_array($product_type, array('regular', 'digital', 'virtual'))) {
                    $product_type = 'regular';
                }

                wp_set_post_terms($p->pk(), $product_type, 'product_type');
            }

            $stockroom_ids = array();

            if (isset($_POST['stock_assoc'])) {


                $stockroom_ids = array_keys($_POST['stock_assoc']);
                $stockroom_ids = array_map('intval', $stockroom_ids);
                $stockroom_ids = array_unique($stockroom_ids);

                $this->db->setQuery("SELECT id FROM `#_shop_stockroom` WHERE id IN(" . implode(',', $stockroom_ids) . ")");
                $stockroom_ids = $this->db->loadArray();
            }
            $stocks = array();

            if (!empty($stockroom_ids)) {

                $que = array();

                foreach ((array) $stockroom_ids as $id) {

                    $que [] = "({$p->id},{$id}," . (int) $_POST['stock_assoc'][$id] . ")";
                }

                $que = "REPLACE INTO `#_shop_products_stockroom_xref` (product_id,stockroom_id,stock) VALUES" . implode(',', $que);

                $this->db->setQuery($que);
            } elseif (!$in_autosave) {

                $this->db->setQuery("DELETE FROM `#_shop_products_stockroom_xref` WHERE product_id = {$p->id} ");
            }

            if ($is_variation && request::getCmd('task', null) != 'save_variation') {

                $this->update_variation_assocs($p->id, (array) request::getVar('property', array()));
            }

            return true;
        }
    }

    public function deleteProducts(array $ids, $delete_variations = false) {

        $db = $this->db;
        $ids = array_map('absint', $ids);
        $ids = array_unique($ids);


        if (!empty($ids)) {



            $db->setQuery("SELECT attribute_id FROM `#_shop_attribute` WHERE product_id IN(" . implode(',', $ids) . ")");

            $this->deleteProductAttributes((array) $db->loadArray(), 'attribute');

            $db->setQuery("DELETE FROM #_shop_products_stockroom_xref WHERE product_id IN(" . implode(',', $ids) . ")");
            if (!$db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            $db->setQuery("DELETE FROM #_shop_variations WHERE pid IN(" . implode(',', $ids) . ")");

            //Can be used to delete product variation on parent product delete
            if ($delete_variations) {
                $db->setQuery("SELECT ID FROM #_posts WHERE post_type='product' AND post_parent IN(" . implode(',', $ids) . ")");
                $rows = $db->loadArray();
                foreach ((array) $rows as $id) {
                    wp_delete_post($id, true);
                }
            }
        }
    }

    public function changeState($id) {

        if (!$this->is_product($id))
            return false;

        $row = Factory::getApplication('shop')->getTable('product')->load($id);




        if ($row->published == 'yes')
            $status = 'draft';
        else
            $status = 'publish';

        remove_action('save_post', 'product_meta_update');
        if (!wp_update_post(array('ID' => $row->pk(), 'post_status' => $status))) {

            return ($row->published == 'yes') ? 1 : 0;
        }

        return ($status == 'publish') ? 1 : 0;
    }

    public function getProductCats($pid) {


        return wp_get_post_terms((int) $pid, 'product_cat');
    }

    public function get_product_brands($pid) {


        return wp_get_post_terms((int) $pid, 'product_brand');
    }

    public function get_product_shipping_groups($pid) {


        return wp_get_post_terms((int) $pid, 'shipping_group');
    }

    public function get_product_shipping_groups_ids($id) {

        return wp_get_post_terms((int) $id, 'shipping_group', array("fields" => "ids"));
    }

    public function getProductStockRoom($stockroom_id, $product_id) {


        $this->db->setQuery("SELECT * FROM `#_shop_shop_products_stockroom_xref` WHERE product_id = " . (int) $product_id . " AND stockroom_id = " . (int) $stockroom_id . " LIMIT 1");


        return $this->db->nextObject();
    }

    public function getProductStockRooms($product_id) {


        $this->db->setQuery("SELECT a.*,b.* FROM `#_shop_products_stockroom_xref` as a

	INNER JOIN `#_shop_stockroom` AS b ON b.id = a.stockroom_id
	
	WHERE a.product_id = " . (int) $product_id);


        return $this->db->loadObjectList();
    }

    private function saveProductAttributes($pid) {

        $pid = (int) $pid;
        $att_model = model::getInstance('attributes', 'shop');

        if ($this->is_product($pid)) {

            $objects = array();

            foreach ((array) $_POST['attribute_id'] as $ak => $att) {
                if ($att['id'])
                    $objects[] = (int) $att['id'];
            }

            $this->db->setQuery('SELECT attribute_id FROM `#_shop_attribute` WHERE product_id = ' . (int) $pid);
            $for_delete = (array) array_diff((array) $this->db->loadArray(), (array) $objects);

            if (!empty($for_delete)) {
                $this->deleteProductAttributes($for_delete, 'attribute');
            }
            unset($for_delete);

            $props = array();
            $sub_attribs = array();

            if (!isset($_POST['attribute_id']) || empty($_POST['attribute_id']))
                return;

            foreach ((array) $_POST['attribute_id'] as $ak => $att) {
                $row = Factory::getApplication('shop')->getTable('shop_attribute');

                if ($att_model->is_attribute($att['id'])) {
                    $row->load($att['id']);
                }

                if (isset($_POST['title'][$ak]['name']))
                    $row->attribute_name = $_POST['title'][$ak]['name'];
                if (isset($_POST['title'][$ak]['required'])) {
                    $row->attribute_required = 'yes';
                } else {
                    $row->attribute_required = 'no';
                }

                if (isset($_POST['title'][$ak]['ordering']))
                    $row->ordering = (int) $_POST['title'][$ak]['ordering'];

                if (isset($_POST['title'][$ak]['hide_attribute_price'])) {
                    $row->hide_attribute_price = 'yes';
                } else {
                    $row->hide_attribute_price = 'no';
                }
                $row->attribute_set_id = null;
                $row->product_id = $pid;
                $row->stockroom_id = null;
                $row->store();


                if (isset($_POST['property'][$ak]['value'])) {


                    foreach ((array) $_POST['property'][$ak]['value'] as $k => $title) {


                        $prop_row = table::getInstance('shop_attribute_property', 'shop');

                        if ($att_model->is_property($_POST['property_id'][$ak]['value'][$k])) {

                            $prop_row->load($_POST['property_id'][$ak]['value'][$k]);
                            if ($prop_row->pk())
                                $props[] = (int) $prop_row->pk();
                        }


                        $prop_row->attribute_id = $row->pk();


                        if (!empty($title))
                            $prop_row->property_name = $title;

                        $prop_row->property_price = (double) $_POST['att_price'][$ak]['value'][$k];

                        if (isset($_POST['oprand'][$ak]['value'][$k]) &&
                                in_array($_POST['oprand'][$ak]['value'][$k], array('*', '-', '+', '/')))
                            $prop_row->oprand = $_POST['oprand'][$ak]['value'][$k];

                        $prop_row->ordering = (int) $_POST['propordering'][$ak]['value'][$k];



                        $prop_row->store();

                        if ($prop_row->pk())
                            $props[] = (int) $prop_row->pk();
                    }
                }
            }


            $for_delete = array();

            $this->db->setQuery("SELECT property_id FROM `#_shop_attribute_property` WHERE attribute_id IN(" . implode(',', $objects) . ")");

            if (!empty($props)) {
                $for_delete = (array) array_diff((array) $this->db->loadArray(), (array) $props);
            } else {

                $for_delete = (array) $this->db->loadArray();
            }


            if (!empty($for_delete)) {

                $this->deleteProductAttributes($for_delete, 'property');
            }

            return true;
        }
    }

    public function deleteProductAttributes(array $ids, $object = 'attribute') {

        $ids = array_map("intval", (array) $ids);

        $path = wp_upload_dir();
        $path = $path['basedir'];


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

    public function getProductAttributes($pid) {



        $this->db->setQuery("(SELECT att.* FROM `#_shop_attribute` as att
	INNER JOIN `#_shop_product_attribsets` as xref ON xref.sid = att.attribute_set_id
	INNER JOIN `#_shop_attribute_set` as aset ON aset.attribute_set_id = xref.sid
	WHERE xref.pid = {$pid} AND aset.published = 'yes' ) UNION 
	(SELECT * FROM `#_shop_attribute` WHERE product_id = {$pid} )
	
	ORDER BY ordering");

        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        $atts = array();

        $db = Factory::getDBO();

        while ($a = $this->db->nextObject()) {

            $db->setQuery("SELECT p.* FROM `#_shop_attribute_property` as p
			  
			   WHERE p.attribute_id = {$a->attribute_id} ORDER BY p.ordering");

            if (!$db->getResource())
                throw new Exception($db->getErrorString());

            $a->properties = array();



            while ($o = $db->nextObject()) {
                $a->properties[$o->property_id] = $o;
            }

            $atts[] = $a;
        }

        return $atts;
    }

    public function variation_exists($parent, array $props) {
        foreach ($props as $k => $v) {
            if (!is_numeric($v) || empty($v))
                unset($props[$k]);
        }

        sort($props);
        $def = md5(implode(',', $props));
        $db = Factory::getDBO();


        $query = "SELECT pid,MD5(GROUP_CONCAT(DISTINCT var.prop ORDER BY var.prop ASC SEPARATOR ',' )) as sig 
                FROM `#_shop_variations` as var
                INNER JOIN #_posts as p ON p.ID = var.pid
                WHERE p.post_parent = " . $parent . " GROUP BY var.pid HAVING sig = '" . $db->secure($def) . "' LIMIT 1";


        // die($query);
        $db->setQuery($query);
        if (!$db->numRows())
            return false;
//die((string) $db->loadResult());
        return (int) $db->loadResult('pid');
    }

    public function create_variation($parent, array $props) {
        if (!$this->is_product($parent) || !count($props)) {
          
            return 1;
        }

        foreach ($props as $k => $v) {
            if (!is_numeric($v) || empty($v))
                unset($props[$k]);
        }

        if (empty($props)) {
            throw new Exception(__("You have to select attribute property to create variation.", "com_Shop"));
        }

        $db = Factory::getDBO();
        if ($this->variation_exists($parent, $props)) {

            throw new Exception(__("Selected variation allready exists", "com_Shop"));
        }
        //sort($props);
        // $def = md5(implode(',', $props));
        if (!request::getString('variation_title', null)) {
            throw new Exception(__("Provide variation title.", "com_Shop"));
        }

        $post = array('post_type' => "product", 'post_title' => request::getString('variation_title', null), 'post_status' => 'draft', 'post_parent' => (int) $parent);

        $res = wp_insert_post($post, true);

        if (!$res) {
            throw new Exception(__("Unable to create the child product.", "com_Shop"));
        }

        $variation_data = Factory::getApplication('shop')->getTable('product')->load($res);
        $variation_data->price = (double) $_POST['variation_price'];
        $variation_data->sku = (double) $_POST['variation_sku'];
        $variation_data->store();

        $values = array();
        foreach ((array) $props as $p) {
            $values[] = "(NULL," . (int) $res . "," . (int) $p . ")";
        }

        $que = "INSERT INTO #_shop_variations VALUES " . implode(',', $values);

        $db->setQuery($que);

        if (!$db->getResource()) {

            wp_delete_post($res, true);
            throw new Exception(__("Unable to store variation associations in database.", "com_Shop"));
        }

        return true;
    }

    protected function update_variation_assocs($product_id, array $props) {


        foreach ($props as $k => $v) {
            if (!is_numeric($v) || empty($v))
                unset($props[$k]);
        }

        if (empty($props))
            return false;

        $post = get_post((int) $product_id);

        if ($pid = $this->variation_exists($post->post_parent, $props)) {
            if ($pid != $product_id) {
                Factory::getApplication('shop')->setMessage(__("The same variation allready exists.", "com_shop"));
            }
            return false;
        }
        $this->db->setQuery("DELETE FROM #_shop_variations WHERE pid = " . (int) $product_id);

        //create signature for the specific variation
        // sort($props);
        // $def = md5(implode(',', $props));

        $values = array();
        foreach ((array) $props as $p) {
            $values[] = "(NULL," . (int) $product_id . "," . (int) $p . ")";
        }

        $que = "INSERT INTO #_shop_variations VALUES " . implode(',', $values);

        $this->db->setQuery($que);

        if (!$this->db->getResource()) {
            return false;
        }

        return true;
    }

    public function get_variation_properties($product_id) {


        $this->db->setQuery("SELECT prop FROM #_shop_variations WHERE pid = " . (int) $product_id);

        return (array) $this->db->loadArray();
    }

}