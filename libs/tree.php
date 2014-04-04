<?php

defined('_VALID_EXEC') or die('access denied');

class treeBase {

    // Structure table and fields
    public $xref = null;
    public $cat = null;
    public $db = null;
    public $taxonomy = null;
    public $cpt = null;

    // Constructor

    public function __construct($cat, $xref, $taxonomy, $term) {

        $this->xref = $xref;
        $this->cat = $cat;
        $this->db = Factory::getDBO();
        $this->taxonomy = $taxonomy;
        $this->cpt = $cpt;
    }

    public function get_root_object() {

        $obj = new stdClass();

        $obj->a11 = 1;
        $obj->a21 = 2;
        $obj->a12 = 1;
        $obj->a22 = 1;
        $obj->category_child_id = 0;
        $obj->category_parent_id = null;

        return $obj;
    }

    public function _get_node($id) {

        if ($id == 0) {

            return array('term' => null, 'node' => $this->get_root_object());
        }


        $term = get_term_by('id', $id, $this->taxonomy);

        if (is_wp_error($term)) {
            return false;
        }

        $this->db->setQuery("SELECT * FROM `" . $this->xref . "`
		  
		WHERE category_child_id = " . (int) $id);

        $row = $this->db->nextObject();
        return array('term' => $term, 'node' => $row);
    }

    public function _get_children($id, $recursive = false, $ret_resource = false) {
        $children = array();

        $node = $this->_get_node($id);
        $node = $node['node'];
        if ($recursive) {

            $this->db->setQuery("
                SELECT * FROM `" . $this->xref . "` as a
                INNER JOIN `#_term_taxonomy` as b ON b.term_id = a.category_child_id
                INNER JOIN `#_terms` as c on c.term_id = b.term_id

                WHERE a.a11 * " . (int) $node->a21 . " >= a.a21 * " . (int) $node->a11 . " AND a.a11 * " . (int) $node->a22 . " < a.a21 * " . (int) $node->a12);
        } else {


            $this->db->setQuery("
                SELECT * FROM `" . $this->xref . "` as a
		INNER JOIN `#_term_taxonomy` as b ON b.term_taxonomy_id = a.category_child_id
                INNER JOIN `#_terms` as c on c.term_id = b.term_id
                WHERE b.parent = " . (int) $id . " ORDER BY a.position ASC
                    ");
        }

        if ($ret_resource)
            return clone $this->db;

        while ($o = $this->db->nextObject())
            $children[$o->term_id] = $o;

        return $children;
    }

    public function moveCat($data) {

        $pid = (int) $data['ref'];
        $cid = (int) $data['id'];
        $pos = (int) $data['position'];

        $cat = $this->_get_node($cid);
        $parent = $this->_get_node($pid);
        $parent = $parent['node'];
        $db = $this->db;

        $db->startTransaction();
        $res = false;
        if ($cat['term']->parent == $pid) {

            $db->setQuery("UPDATE " . $this->xref . " SET position=position+1 WHERE category_parent_id = {$pid} AND position >= {$pos}");

            $db->setQuery("UPDATE " . $this->xref . " SET position={$pos} 	WHERE category_child_id = {$cid} LIMIT 1");

            $db->commit();
        } else if ($cat['term']->term_id != $pid) {

            $db->setQuery("UPDATE " . $this->xref . " SET position=position+1 WHERE category_parent_id = {$pid} AND position >= {$pos}");

            $db->setQuery("UPDATE " . $this->xref . " SET position=position-1 WHERE category_parent_id = {$cat['term']->parent} AND position > {$pos}");


            $db->setQuery("SELECT * FROM " . $this->xref . " WHERE category_parent_id = {$pid} ORDER BY a11 DESC, a21 DESC LIMIT 1");


            $lastRight = null;
            $lastRight = $db->nextObject();


            $child = array();


            if (empty($lastRight)) {

                $child['a11'] = $parent->a11 + $parent->a12;
                $child['a21'] = $parent->a21 + $parent->a22;
                $child['a12'] = $parent->a12;
                $child['a22'] = $parent->a22;
            } else {

                $child['a11'] = $lastRight->a11 + $parent->a11;
                $child['a21'] = $lastRight->a21 + $parent->a21;
                $child['a12'] = $lastRight->a11;
                $child['a22'] = $lastRight->a21;
            }

            $db->setQuery("UPDATE " . $this->xref . "
							SET
								category_parent_id = {$pid},
								position={$pos}, 
								a11 = {$child['a11']},
								a12 = {$child['a12']},
								a21 = {$child['a21']},
								a22 = {$child['a22']}
							WHERE category_child_id = {$cid} LIMIT 1");


            $cat = $cat['node'];

            $que = "UPDATE " . $this->xref . " as c
					INNER JOIN " . $this->xref . " AS t ON t.category_child_id = {$cid}
					SET 
						c.a11 = abs(" . $cat->a12 . "*c.a21-" . $cat->a22 . "*c.a11)*t.a11 + ((c.a11 - abs(" . $cat->a12 . "*c.a21-" . $cat->a22 . "*c.a11)*" . $cat->a11 . ")/" . $cat->a12 . ")*t.a12,
						c.a21 = abs(" . $cat->a12 . "*c.a21-" . $cat->a22 . "*c.a11)*t.a21 + ((c.a21 - abs(" . $cat->a12 . "*c.a21-" . $cat->a22 . "*c.a11)*" . $cat->a21 . ")/" . $cat->a22 . ")*t.a22,
						c.a12 = abs(CAST((" . $cat->a11 . "*c.a22-" . $cat->a21 . "*c.a12) AS SIGNED))*t.a12 + ((c.a12 - abs(CAST((" . $cat->a11 . "*c.a22-" . $cat->a21 . "*c.a12) AS SIGNED))*" . $cat->a12 . ")/" . $cat->a11 . " )*t.a11,
						c.a22 = abs(CAST((" . $cat->a11 . "*c.a22-" . $cat->a21 . "*c.a12) AS SIGNED))*t.a22 + ((c.a22 - abs(CAST((" . $cat->a11 . "*c.a22-" . $cat->a21 . "*c.a12) AS SIGNED))*" . $cat->a22 . ")/" . $cat->a21 . ")*t.a21
					
					WHERE	c.a11 * " . $cat->a21 . " > c.a21 * " . $cat->a11 . "
							AND c.a11 * " . $cat->a22 . " < c.a21 * " . $cat->a12;





            $db->setQuery($que);

            $res = wp_update_term($cid, $this->taxonomy, array("parent" => $pid));
            if (is_wp_error($res)) {

                $db->rollback();
                return false;
            }
            $db->commit();
        }
    }

    public function _get_path($id) {

        $node = $this->_get_node($id);
        $path = array();
        if (empty($node))
            return false;
        $this->db->setQuery("
            SELECT * FROM `" . $this->xref . "` as a
            LEFT JOIN `" . $this->cat . "` as b ON b.category_id = a.category_child_id

            WHERE a11 * " . (int) $node->a21 . " < a.a21 * " . (int) $node->a11 . " AND a.a11 * " . (int) $node->a22 . " <= a.a21 * " . (int) $node->a12 . " ORDER BY a.a11/a.a21 ASC

            ");



        while ($o = $this->db->nextObject())
            $path[$o->category_id] = $o;
        return $path;
    }

    public function _create($data) {

        $db = $this->db;


        $parent = $data['category_parent_id'];
        $title = $data['title'];
        $type = 'default';

        if (empty($parent))
            $parent = 0;
        if (empty($title))
            return false;




        $id = wp_insert_term($title, $this->taxonomy, array(
            'parent' => $parent,
            'post_type' => $this->cpt
        ));

        if (is_wp_error($id)) {

            return $id;
        }

        $id = $id['term_id'];

        if (!$id)
            return false;




        $row = null;


        if (!$parent)
            $row = $this->_get_node(0);
        else
            $row = $this->_get_node($parent);

        $row = $row['node'];

        $db->setQuery("SELECT * FROM " . $this->xref . " WHERE category_parent_id = {$row->category_child_id} ORDER BY a11 DESC, a21 DESC LIMIT 1");

        $child = array();

        $child['category_parent_id'] = $row->category_child_id;
        $child['category_child_id'] = $id;

        $lastRight = null;
        $lastRight = $db->nextObject();


        if (empty($lastRight)) {

            $child['a11'] = $row->a11 + $row->a12;
            $child['a21'] = $row->a21 + $row->a22;
            $child['a12'] = $row->a12;
            $child['a22'] = $row->a22;
        } else {

            $child['a11'] = $lastRight->a11 + $row->a11;
            $child['a21'] = $lastRight->a21 + $row->a21;
            $child['a12'] = $lastRight->a11;
            $child['a22'] = $lastRight->a21;
        }

        $child['position'] = (int) $data['position'];
        $child['type'] = 'default';
        $db->setQuery("INSERT INTO " . $this->xref . " " . $db->buildQuery($child));


        if (!$db->getResource()) {
            wp_delete_term($id, $this->taxonomy);

            return false;
        }



        return $id;
    }

    public function _remove($id) {
        if ((int) $id === 0) {
            return false;
        }
        $node = $this->_get_node($id);


        $this->db->setQuery("SELECT category_child_id FROM `" . $this->xref . "` WHERE category_parent_id = " . $id);

        if (!$this->db->getResource()) {
            return false;
        }
        $rows = $this->db->loadArray();




        // deleting node and its children
        if (!empty($rows)) {

            $this->db->startTransaction();

            $que = "UPDATE `" . $this->xref . "` SET category_parent_id = " . (int) $node['term']->parent . " WHERE category_child_id IN(" . implode(',', $rows) . ")";

            $this->db->setQuery($que);

            if (!$this->db->getResource()) {
                return false;
            }
        }

        $this->db->setQuery("DELETE FROM `" . $this->xref . "` WHERE category_child_id = " . (int) $id . " LIMIT 1");

        $res = null;

        if ($this->db->getResource()) {

            $res = wp_delete_term($id, $this->taxonomy);
        }
        if (true === $res) {

            $this->db->commit();

            return true;
        } else {

            $this->db->rollback();
        }
        return false;
    }

    public function hasChildrens($id) {

        $this->db->setQuery("SELECT count(*) FROM #_term_taxonomy WHERE taxonomy = 'product_cat' && parent = {$id}");

        return (int) $this->db->loadresult();
    }

    public function rename($id, $title) {


        $args['name'] = $title;
        $args['slug'] = '';

        $res = wp_update_term($id, $this->taxonomy, $args);

        if (is_wp_error($res)) {
            return false;
        }
        return true;
    }

}