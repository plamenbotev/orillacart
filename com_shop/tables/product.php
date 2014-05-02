<?php

defined('_VALID_EXEC') or die('access denied');

class productTable extends table {

    public $post = null;
    public $id = null;
    public $name = null;
    public $type = 'regular';
    public $download_expiry = '';
    public $download_limit = '';
    public $download_limit_multiply_qty = 0;
    public $download_choose_file = 1;
    public $sku = null;
    public $published = 'draft';
    public $onsale = 'no';
    public $special = 'no';
    public $expired = 'no';
    public $not_for_sale = 'no';
	public $hide_price = 'global';
    public $price = null;
    public $discount_price = null;
    public $discount_start = '0000-00-00 00:00:00';
    public $discount_end = '0000-00-00 00:00:00';
    public $vat = 'global';
    public $desc = null;
    public $page_title = null;
    public $page_heading = null;
    public $page_meta_keywords = null;
    public $page_meta_description = null;
    public $page_meta_robot_info = null;
    public $product_length = null;
    public $product_width = null;
    public $product_height = null;
    public $product_volume = null;
    public $product_diameter = null;
    public $product_weight = null;
    public $tax_group_id = null;
    public $manage_stock = 'global';
    public $stock = 'global';
    public $tpl = '';

    public function __construct() {
        parent::__construct('id', '#_posts');
    }

    public function store() {

        $this->price = str_replace(',', '.', $this->price);
        $this->discount_price = str_replace(',', '.', $this->discount_price);

        if (!in_array($this->type, array('regular', 'digital', 'virtual'))) {
            $this->type = 'regular';
        }

        $fields = $this->getPublicFields();

        foreach ((array) $fields as $f => $v) {
            if ($f == 'post')
                continue;
            if (in_array($f, array('id', 'name', 'desc', 'published')))
                continue;
            if (empty($v))
                $v = '';
            update_post_meta((int) $this->id, "_{$f}", $v);
        }
        return $this;
    }

    public function load($id) {

        if (!$id)
            return $this;
        $row = get_post(absint($id));

        if (!$row) {
            throw new Exception(__("no such product", "com_shop"));
        }

        $this->post = $row;

        $this->id = $row->ID;
        $this->name = $row->post_title;
        $this->desc = $row->post_content;
        $this->published = ($row->post_status == 'publish') ? 'yes' : 'no';

        $meta = get_post_custom($this->id);

        $fields = $this->getPublicFields();
        if (is_array($meta)) {
            foreach ((array) $fields as $f => $v) {
                if (in_array($f, array('id', 'name', 'desc', 'published')))
                    continue;
                if (array_key_exists("_" . $f, $meta)) {
                    $this->{$f} = $meta["_" . $f][0];
                }
            }
        }

        return $this;
    }

    public function delete() {
        return (bool) wp_delete_post($this->id, true);
    }

}