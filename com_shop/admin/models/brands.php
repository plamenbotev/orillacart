<?php

defined('_VALID_EXEC') or die('access denied');

class brandsModel extends model {

    public function is_brand($id) {

        return (bool) term_exists((int) $id, 'product_brand');
    }

    public function total() {

        $this->db->setQuery("SELECT count(*) FROM #_term_taxonomy WHERE taxonomy='product_brand'");

        return (int) $this->db->loadResult();
    }

    public function listBrands() {

        $start = request::getInt('limitstart', 0);
        $limit = (int) Factory::getApplication('shop')->getParams()->get('objects_per_page');

        $brands = get_terms('product_brand', 'hide_empty=0&offset' . $start . '&number=' . $limit);

        if (is_wp_error($brands)) {
            throw new Exception($brands->get_error_message());
        }
        return $brands;
    }

    public function getAllBrands() {

        $brands = get_terms('product_brand', 'hide_empty=0');

        if (is_wp_error($brands)) {
            throw new Exception($brands->get_error_message());
        }
        return $brands;
    }

    public function loadBrand($id) {

        if (!$this->is_brand($id))
            return false;

        return get_term_by('id', (int) $id, 'product_brand');
    }

}