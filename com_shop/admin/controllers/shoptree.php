<?php

defined('_VALID_EXEC') or die('access denied');

/**
 * Data source for the category filter tree on the stockroom page.
 */
class shopControllerShopTree extends controller {

    public function __default() {

        $operation = isset($_REQUEST["operation"]) ? (string) $_REQUEST["operation"] : '';

        if (in_array($operation, array('get_children', 'search'))) {
            header("HTTP/1.0 200 OK");
            header('Content-type: text/json; charset=utf-8');
            header("Cache-Control: no-cache, must-revalidate");
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Pragma: no-cache");
            echo $this->{$operation}($_REQUEST);
            die();
        }
        header("HTTP/1.0 404 Not Found");
    }

    /**
     * Direct subcategories of a product category, in display order.
     */
    protected function children_of($parent) {

        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'parent' => (int) $parent,
            'hide_empty' => false,
            'orderby' => 'name',
        ));

        if (is_wp_error($terms)) {
            return array();
        }

        $term_meta = Factory::getComponent('shop')->getHelper('term_meta');
        $position = array();
        foreach ($terms as $t) {
            $position[$t->term_id] = (int) $term_meta->get($t->term_id, 'position', true);
        }

        // usort is stable on PHP 8, so the name order from get_terms breaks ties
        usort($terms, function ($a, $b) use ($position) {
            if ($position[$a->term_id] != $position[$b->term_id]) {
                return $position[$a->term_id] < $position[$b->term_id] ? -1 : 1;
            }
            return 0;
        });

        return $terms;
    }

    protected function has_children($id) {

        $children = get_terms(array(
            'taxonomy' => 'product_cat',
            'parent' => (int) $id,
            'hide_empty' => false,
            'fields' => 'ids',
            'number' => 1,
        ));

        return !is_wp_error($children) && !empty($children);
    }

    public function get_children($data) {

        $result = array();

        if ($data['category_child_id'] == -1) {

            $result[] = array(
                "attr" => array("id" => "node_0", "rel" => 'tree'),
                "data" => 'root',
                "state" => $this->has_children(0) ? "closed" : ""
            );
        } else {

            $selected_cats = array();

            if (isset($data['pid']) && is_numeric($data['pid']) && $this->getModel('product_admin')->is_product($data['pid'])) {

                $selected_cats = $this->getModel('product_admin')->getProductCatIds($data['pid']);
            } else if (isset($_SESSION['filter']['cats'])) {

                $selected_cats = ArrayHelper::toInt($_SESSION['filter']['cats']);
            }

            foreach ($this->children_of($data['category_child_id']) as $o) {

                $node = array(
                    "attr" => array("id" => "node_" . $o->term_id, "rel" => 'default'),
                    "data" => $o->name,
                    "state" => $this->has_children($o->term_id) ? "closed" : "",
                );

                if (in_array($o->term_id, $selected_cats)) {
                    $node['attr']['class'] = 'checked';
                }

                $result[] = $node;
            }
        }
        return json_encode($result);
    }

    public function search($data) {
        return json_encode(array());
    }

}
