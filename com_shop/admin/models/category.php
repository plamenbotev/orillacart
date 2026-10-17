<?php

defined('_VALID_EXEC') or die('access denied');

class categoryModel extends model {

    /**
     * Store the shop specific term meta posted with the WP term form.
     * Terms created or updated without the form (imports, wp_insert_term) are left untouched.
     */
    public function saveMeta($cid, $post) {

        if (!$cid || !$this->is_cat($cid) || !isset($post['shop_category_meta']))
            return false;

        if (!current_user_can('edit_product_terms'))
            return false;

        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');

        if (!empty($post['thumbnail_id'])) {
            $term_meta->update($cid, 'thumbnail_id', (int) $post['thumbnail_id']);
        } else {
            $term_meta->delete($cid, 'thumbnail_id');
        }

        $list_template = (string) $post['list_template'];
        if ($list_template !== '' && !in_array($list_template, $this->getItemListTemplates())) {
            $list_template = '';
        }

        $view_style = (string) $post['view_style'];
        if (!in_array($view_style, array('', 'grid', 'list'))) {
            $view_style = '';
        }

        $term_meta->update($cid, 'products_per_row', (int) $post['products_per_row']);
        $term_meta->update($cid, 'list_template', $list_template);
        $term_meta->update($cid, 'view_style', $view_style);
        $term_meta->update($cid, 'position', (int) $post['position']);

        return true;
    }

    public function is_cat($cid) {

        return (bool) term_exists((int) $cid, 'product_cat');
    }

    /**
     * Shop meta of a category, or the defaults for a new one when $cid is 0.
     */
    public function loadMeta($cid) {

        $row = new stdClass();
        $row->image_id = 0;
        $row->image_src = '';
        $row->products_per_row = '';
        $row->list_template = '';
        $row->view_style = '';
        $row->position = 0;

        if (!$cid || !$this->is_cat($cid)) {
            return $row;
        }

        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');
        $row->image_id = (int) $term_meta->get($cid, 'thumbnail_id', true);

        if ($row->image_id) {
            $att = wp_get_attachment_image_src($row->image_id);
            $row->image_src = $att ? $att[0] : '';
        }
        $row->products_per_row = $term_meta->get($cid, 'products_per_row', true);
        $row->list_template = $term_meta->get($cid, 'list_template', true);
        $row->view_style = $term_meta->get($cid, 'view_style', true);
        $row->position = (int) $term_meta->get($cid, 'position', true);

        return $row;
    }

    public function getItemListTemplates() {
        $app = Factory::getComponent('shop');

        return (array) Path::folders($app->getComponentPath() . "/../front/views/product_list/templates");
    }

    public function getProductTemplates() {
        $app = Factory::getComponent('shop');

        return (array) Path::folders($app->getComponentPath() . "/../front/views/product/templates");
    }

}
