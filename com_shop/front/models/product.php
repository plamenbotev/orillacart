<?php

class productModel extends model {

    public function load_product($id) {

        $p = new stdClass();
        $p->price = null;
        $p->thumb = null;
        $p->image_title = null;

        $row = Factory::getComponent('shop')->getTable('product')->load($id);

        $attribs = $this->getProductAttributes($id);
        $def = !empty($attribs->def) ? (array) $attribs->def : array();


        $p->price = Factory::getComponent('shop')->getHelper('product_helper')->get_price_with_tax($row);

        if (has_post_thumbnail($id)) {
            $thumb = wp_get_attachment_image_src(get_post_thumbnail_id(), 'product_thumb');
            $p->thumb = $thumb[0];
            $meta = wp_get_attachment_metadata(get_post_thumbnail_id());

            if (isset($meta['image_meta']['title']))
                $p->image_title = $meta['image_meta']['title'];
        }
        else {
            $p->thumb = '';
            $p->image_title = '';
        }

        $p->hide_price = get_post_meta((int) $id, '_hide_price', true);
        $p->not_for_sale = get_post_meta((int) $id, '_not_for_sale', true);
        $p->special = get_post_meta((int) $id, '_special', true);
        $p->on_sale = get_post_meta((int) $id, '_on_sale', true);
        $p->expired = get_post_meta((int) $id, '_expired', true);

        return $p;
    }

    public function get_product_data($id) {

        $helper = Factory::getComponent('shop')->getHelper('product_helper');
        $app = Factory::getComponent('shop');
        $pid = (int) $id;

        if (!$pid || !$this->is_product($pid)) {
            throw new not_found_404(__('no such product', 'com_shop'));
        }

        $params = Factory::getComponent('shop')->getParams();

        $row = new stdClass();

        $row->is_configurable = false;
        $row->configurable_parent_id = $pid;



        $row->product = Factory::getComponent('shop')->getTable('product')->load($pid);

        if ($row->product->published == 'no') {
            throw new not_found_404(__('no such product', 'com_shop'));
        }

        $images = $this->loadImages($pid);
        $row->amount = $this->getAvailableAmount($pid);
        $row->selected_props = array();
        //if product is child product, load parent attributes and preselec them
        if ($row->product->post->post_parent) {

            $row->is_configurable = true;
            $row->configurable_parent_id = $row->product->post->post_parent;

            $atts = $this->getProductAttributes($row->product->post->post_parent);
            $this->db->setQuery("SELECT prop FROM #_shop_variations WHERE pid = " . (int) $pid);
            $row->selected_props = (array) $this->db->loadArray();
        } else {
            $atts = $this->getProductAttributes($pid);
        }

        $this->db->setQuery("SELECT COUNT(*) FROM #_posts WHERE post_type = 'product' AND post_parent = " . (int) $pid);
        $has_childs = (int) $this->db->loadResult();

        if ($has_childs) {
            $row->is_configurable = true;
            $row->configurable_parent_id = $pid;
        }

        $row->availability = $this->is_variation_available($pid);
        $row->attributes = $atts->atts;
        $price = $helper->get_price_with_tax($row->product);
        $row->price = $price;
        $height = 0;
        $row->images = array();
        $height = array();

        foreach ((array) $images as $i) {
            $img = new stdClass();

            $img->medium = wp_get_attachment_image_src((int) $i, 'product_medium');
            $height[] = $img->medium[2];
            $img->medium = $img->medium[0];
            $img->mini = wp_get_attachment_image_src((int) $i, 'product_mini');
            $img->mini = $img->mini[0];
            $img->image = wp_get_attachment_image_src((int) $i, 'full');
            $img->image = $img->image[0];
            $row->images[] = $img;
        }

        if (!empty($height)) {
            $row->gallery_height = max($height) . "px";
        } else {
            $row->gallery_height = 'auto';
        }
        return $row;
    }

    public function is_product($id) {

        if (get_post_type((int) $id) == 'product')
            return true;
        return false;
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

        while ($image_query->have_posts()) {
            $image_query->the_post();
            $files[get_the_ID()] = get_the_title();
        }
        wp_reset_postdata();

        return $files;
    }

    public function loadImages($id) {
        global $post, $wp_query;

        if (!$this->is_product($id))
            return false;

        $images = array();

        $img = null;
        $image_query = new WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => -1, 'post_parent' => (int) $id, 'orderby' => 'menu_order', 'order' => 'ASC', 'tax_query' =>
            array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => array('digital'),
                    'operator' => 'NOT IN'
                )
            )
        ));

        while ($image_query->have_posts()) {
            $image_query->the_post();
            $images[] = get_the_ID();
        }

        wp_reset_postdata();

        return $images;
    }

    public function getAvailableAmount($pid) {
        if (!$this->is_product($pid))
            return 0;
        return $this->is_variation_available($pid);
    }

    public function get_child_products($pid) {
        global $post;

        if (is_object($post)) {
            $tmp = clone $post;
        }

        $res = array();
        $childs = new WP_Query();
        $childs->query('post_type=product&post_parent=' . $pid . '&posts_per_page=-1');
        while ($childs->have_posts()) {
            $childs->the_post();
            $res[get_the_ID()] = get_the_title();
        }

        wp_reset_postdata();
        wp_reset_query();

        if (isset($tmp)) {
            $post = $tmp;
        }
        return $res;
    }

    public function is_variation_available($pid, $props = array()) {
        $amounts = array();
        $row = Factory::getComponent('shop')->getTable('product')->load($pid);
        if (!$row->pk()) {

            return 0;
        }

        foreach ((array) $props as $k => $v) {
            if (empty($v))
                unset($props[$k]);
        }

        // the product is variation so we exclude the attributes
        if ($row->post->post_parent) {
            $props = array();
        }
        if ($row->manage_stock == 'no' || ($row->manage_stock == 'global' && !Factory::getComponent('shop')->getParams()->get('checkStock')))
            return true;

        if ($row->stock == "no")
            return false;
        if ($row->stock == "yes")
            return true;

        $pid = (int) $pid;

        if (!empty($props)) {

            $props = array_map('intval', (array) $props);

            $this->db->setQuery("
	(SELECT att.* FROM `#_shop_attribute` as att
	INNER JOIN `#_shop_product_attribsets` as xref ON xref.sid = att.attribute_set_id
	INNER JOIN `#_shop_attribute_set` as aset ON aset.attribute_set_id = xref.sid
	WHERE xref.pid = {$pid} AND aset.published = 'yes' ) UNION 
	(SELECT * FROM `#_shop_attribute` WHERE product_id = {$pid} )
	
        ORDER BY ordering");

            if (!$this->db->getResource()) {
                throw new Exception($db->getErrorString());
            }

            $atts = $this->db->loadArray();

            $this->db->setQuery("
                SELECT p.property_id, p.ordering, 
                sum(s.stock) - ifnull( (SELECT SUM(qty) FROM `#_shop_cart` WHERE section = 'property' AND product_id = p.property_id ),0) as available
                
                 FROM `#_shop_attribute_property` as p
                 LEFT JOIN `#_shop_property_stockroom_xref` AS s ON s.property_id = p.property_id
                 WHERE p.attribute_id IN(" . implode(',', $atts) . ") 
                 AND p.property_id IN(" . implode(',', $props) . ") 
                 AND s.stock > 0 GROUP BY p.property_id 
                 HAVING available > 0
                 
                 UNION DISTINCT
                  SELECT p.property_id, p.ordering, null as available FROM `#_shop_attribute_property` as p
                  LEFT JOIN `#_shop_property_stockroom_xref` AS s ON s.property_id = p.property_id
                  WHERE p.attribute_id IN(" . implode(',', $atts) . ") AND p.property_id IN(" . implode(',', $props) . ") 
                  AND s.stock IS NULL GROUP BY p.property_id
                  ORDER BY ordering");

            if (!$this->db->getResource()) {
                throw new Exception($this->db->getErrorString());
            }

            $new = array();

            while ($row = $this->db->nextObject()) {

                $new[] = $row->property_id;
                if (!is_null($row->available)) {
                    $amounts[] = $row->available;
                }
            }




            $new = (array) array_diff((array) $props, (array) $new);


            if (!empty($new)) {
                return 0;
            }
        }

        $this->db->setQuery("
            SELECT SUM(p.stock) - IFNULL((
            SELECT SUM(qty)
            FROM `#_shop_cart`
            WHERE section = 'product' AND product_id = p.product_id), 0) as available
            
            FROM `#_shop_products_stockroom_xref` AS p
            WHERE p.product_id = " . (int) $pid . "
            AND p.stock >0
            GROUP BY p.product_id
            HAVING available > 0
            
            UNION DISTINCT
            SELECT null as available
            FROM `#_posts` AS p
            LEFT JOIN `#_shop_products_stockroom_xref` AS s ON s.product_id = p.ID
            WHERE s.stock IS NULL
            AND p.ID = " . (int) $pid . "
            GROUP BY p.ID");


        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }

        if (!$this->db->numRows()) {
            return 0;
        } else {
            $row = $this->db->nextObject();
            if (!is_null($row->available)) {
                $amounts[] = $row->available;
            }
        }

        if (empty($amounts))
            return true;


        return min($amounts);
    }

    public function getProductAttributes($pid) {

        static $res = null;
        if (is_object($res) && $res->pid == $pid) {
            return $res;
        } else {
            $res = null;
        }

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

        $join = '';
        if ($this->is_variable($pid)) {

            $join = ' INNER JOIN #_shop_variations AS va ON va.prop = p.property_id ';
        }

        while ($a = $this->db->nextObject()) {

            $db->setQuery("SELECT p.* FROM `#_shop_attribute_property` as p
                           " . $join . "
			   WHERE p.attribute_id = {$a->attribute_id} ORDER BY p.ordering");

            if (!$db->getResource())
                throw new Exception($db->getErrorString());

            $a->properties = array();



            while ($o = $db->nextObject()) {
                $a->properties[$o->property_id] = $o;
            }
            if (empty($a->properties))
                continue;
            $atts[] = $a;
        }

        $res = new stdClass();
        $res->pid = $pid;
        $res->atts = (array) $atts;

        return $res;
    }

    public function get_variation($parent, array $props) {

        if (!$this->is_product($parent))
            return false;

        foreach ((array) $props as $k => $v) {

            if (!is_numeric($v) || empty($v)) {
                unset($props[$k]);
            }
        }

        if (empty($props))
            return null;

        sort($props);
        $def = md5(implode(',', $props));
        $db = Factory::getDBO();


        $query = "SELECT pid,MD5(GROUP_CONCAT(DISTINCT var.prop ORDER BY var.prop ASC SEPARATOR ',' )) as sig 
                FROM `#_shop_variations` as var
                INNER JOIN #_posts as p ON p.ID = var.pid
                WHERE p.post_parent = " . (int) $parent . " GROUP BY var.pid HAVING sig = '" . $db->secure($def) . "' LIMIT 1";



        $db->setQuery($query);
        if (!$db->numRows())
            return false;


        return (int) $db->loadResult(0);
    }

    public function is_variable($pid = 0) {


        $db = Factory::getDBO();

        $db->setQuery("SELECT COUNT(*) FROM #_posts WHERE post_type = 'product' AND post_parent = " . (int) $pid);

        if ($db->loadResult() > 0)
            return true;

        $db->setQuery("SELECT post_parent FROM #_posts WHERE ID = " . (int) $pid . " AND post_type='product' LIMIT 1");

        if ($db->loadResult() > 0)
            return true;

        return false;
    }

}
