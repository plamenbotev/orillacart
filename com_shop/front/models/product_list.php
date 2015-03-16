<?php

class product_listModel extends model {

    protected $order_by = null;
    protected $order_type = null;

    public function __construct() {

        $input = Factory::getApplication()->getInput();

        $order = $input->get('product_list_order', null, "CMD");

        if (!in_array($order, array('id', 'name', 'price_lowest', 'price_highest', 'ordering'))) {
            if (isset($_SESSION['product_list_order']) && $_SESSION['product_list_order']) {
                $order = $_SESSION['product_list_order'];
            } else {
                $order = Factory::getComponent('shop')->getParams()->get('productSort');
            }
        }
        $_SESSION['product_list_order'] = $order;

        switch ($order) {
            case "name":
                $this->order_by = 'title';
                $this->order_type = 'ASC';
                break;

            case "price_lowest":
                $this->order_by = "price";
                $this->order_type = "ASC";
                break;
            case "price_highest":
                $this->order_by = "price";
                $this->order_type = "DESC";
                break;
            case "ordering":
                $this->order_by = "menu_order";
                $this->order_type = "ASC";
                break;

            case "id":
            default:
                $this->order_by = "ID";
                $this->order_type = "DESC";
                break;
        }
        parent::__construct();
    }

    public function is_category($cid) {
        return (bool) term_exists((int) $cid, 'product_cat');
    }

    public function load($cid = null) {

        $input = Factory::getApplication()->getInput();

        if ($cid == null) {
            $cid = $input->get('cid', null, "INT");
        }

        $term = get_term_by('id', (int) $cid, 'product_cat');

        if (!$term) {
            return false;
        }
        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');
        $term->image_id = $term_meta->get($cid, 'thumbnail_id', true);

        if ($term->image_id) {
            $att = wp_get_attachment_image_src($term->image_id);

            $term->image_src = $att[0];
        } else {
            $term->image_src = '';
        }
        $term->products_per_row = $term_meta->get($cid, 'products_per_row', true);
        $term->list_template = $term_meta->get($cid, 'list_template', true);
        $term->view_style = $term_meta->get($cid, 'view_style', true);
        return $term;
    }

    public function getSubCats($id, $exclude = array()) {
        $exclude = array_map('intval', $exclude);
        if (!$id || !$this->is_category($id))
            $id = 0;

        $db = Factory::getDBO();

        $and = '';
        if (!empty($exclude)) {
            $and = " AND taxonomy.term_id NOT IN(" . implode(',', $exclude) . ")";
        }

        $db->setQuery("SELECT terms.*,taxonomy.* FROM #_terms as terms
            INNER JOIN #_term_taxonomy as taxonomy ON taxonomy.term_id = terms.term_id
            INNER JOIN #_shop_category_xref AS c ON c.category_child_id = terms.term_id
            where taxonomy.taxonomy = 'product_cat' AND taxonomy.parent = {$id} {$and} ORDER BY c.position ASC");

        if (!$db->getResource()) {
            throw new Exception($db->getErrorString());
        }

        $rows = $db->loadObjectList();
        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');

        foreach ((array) $rows as $row) {
            $row->image_id = $term_meta->get($row->term_id, 'thumbnail_id', true);

            if ($row->image_id) {
                $att = wp_get_attachment_image_src($row->image_id, 'cat_thumb');
                $row->image_src = $att[0];
            } else {
                $row->image_src = '';
            }
        }

        return $rows;
    }

    public function getProductsOrderByPrice($sql, $query) {
        $db = Factory::getDBO();
        if ($query->is_main_query()) {

            $sql['fields'] .= $db->parseQuery(', CAST(#_postmeta.meta_value AS DECIMAL(65,30)) as thePrice');
            $sql['orderby'] = 'thePrice ' . $this->order_type;

            $sql = (array) apply_filters("orillacart_order_by_price_sql", $sql, $query, $this->order_type);
        }
        return $sql;
    }

    public function getProducts() {

        $req = request::get_wp_original_request();

        $paged = isset($req['paged']) ? (int) $req['paged'] : 1;

        $input = Factory::getApplication()->getInput();


        request::set_wp_var('posts_per_page', (int) Factory::getComponent('shop')->getParams()->get('objects_per_page'));
        request::set_wp_var('post_status', 'publish');
        request::set_wp_var('post_type', 'product');
        request::set_wp_var('paged', $paged);
        if (!Factory::getComponent('shop')->getParams()->get('list_variations')) {
            request::set_wp_var('post_parent', 0);
        }

        if (( (isset($req['post_type']) && 'product' == $req['post_type']) || $input->get('con', null, "CMD") == 'product_list' ) && !isset($req['product_cat']) && !isset($req['product_brand']) && !isset($req['product_tag'])) {

            if (Factory::getComponent('shop')->getParams()->get('front_page_cat')) {
                $term = get_term_by('id', (int) Factory::getComponent('shop')->getParams()->get('front_page_cat'), 'product_cat');
                if (!empty($term->slug)) {
                    $taxquery = array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field' => 'slug',
                            'terms' => (string) $term->slug,
                            'include_children' => 0
                        )
                    );


                    request::set_wp_var('tax_query', $taxquery);
                    request::set_wp_var('product_cat', (string) $term->slug);
                }
            }
        } else if (isset($req['product_cat'])) {
            $taxquery = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => request::get_wp_var('product_cat'),
                    'include_children' => 0
                )
            );
            request::set_wp_var('tax_query', $taxquery);
        }

        if ($this->order_by == 'price') {
            request::set_wp_var('meta_key', '_price');
            request::set_wp_var('orderby', 'meta_value');
            request::set_wp_var('order', $this->order_type);
            //include the default selected attributes for the price ordering
            add_filter('posts_clauses_request', array($this, 'getProductsOrderByPrice'), 10, 2);
        } else {
            request::set_wp_var('orderby', $this->order_by);
            request::set_wp_var('order', $this->order_type);
        }
    }

}
