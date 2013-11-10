<?php

class term_meta {

    public function add($term_id, $meta_key, $meta_value, $unique = false) {
        return add_metadata('term', $term_id, $meta_key, $meta_value, $unique);
    }

    public function delete($term_id, $meta_key, $meta_value = '') {
        return delete_metadata('term', $term_id, $meta_key, $meta_value);
    }

    public function get($term_id, $key, $single = false) {
        return get_metadata('term', $term_id, $key, $single);
    }

    public function update($term_id, $meta_key, $meta_value, $prev_value = '') {

        return update_metadata('term', $term_id, $meta_key, $meta_value, $prev_value);
    }

}
