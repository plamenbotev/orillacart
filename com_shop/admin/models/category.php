<?php

defined('_VALID_EXEC') or die('access denied');

class categoryModel extends model {

    public function saveCat(&$d) {

        $cid = (int) $d['category_id'];

        $post = Factory::getApplication()->getInput()->post;

        if (!$cid || !$this->is_cat($cid))
            throw new message(__("Invalid category", "com_shop"));


        $args = array();


        $args['name'] = $d['category_name'];
        $args['description'] = $d['category_description'];
        $args['slug'] = '';
        if ($this->is_cat($cid)) {
            $res = wp_update_term($cid, 'product_cat', $args);
        } else {
            $res = wp_insert_term($cid, 'product_cat', $args);
        }
        if (is_wp_error($res)) {
            throw new message($res->get_error_message());
        }


        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');
        if (isset($post['thumbnail_id']) && !empty($post['thumbnail_id'])) {

            $term_meta->update($cid, 'thumbnail_id', (int) $post['thumbnail_id']);
        } else {
            $term_meta->delete($cid, 'thumbnail_id');
        }

        $term_meta->update($cid, 'products_per_row', (int) $post['products_per_row']);
        $term_meta->update($cid, 'list_template', (string) $post['list_template']);
        $term_meta->update($cid, 'view_style', (string) $post['view_style']);

        return $cid;
    }

    public function is_cat($cid) {


        return (bool) term_exists((int) $cid, 'product_cat');
    }

    public function loadCat($cid) {



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

    public function getItemListTemplates() {
        $app = Factory::getComponent('shop');

        return (array) Path::folders($app->getComponentPath() . "/../front/views/product_list/templates");
    }

    public function getProductTemplates() {
        $app = Factory::getComponent('shop');

        return (array) Path::folders($app->getComponentPath() . "/../front/views/product/templates");
    }

}
