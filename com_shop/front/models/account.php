<?php

class accountModel extends Model {

    protected $total_user_orders = null;

    public function get_total_user_orders() {
        return (int) $this->total_user_orders;
    }

    public function get_user_orders($uid, $start = 0, $limit = 10) {

        $q = new WP_Query(array(
            "post_type" => "shop_order",
            "meta_key" => "_customer_id",
            "meta_value" => (int) $uid,
            "paged" => (int) $start,
            "posts_per_page" => (int) $limit
        ));

        $this->total_user_orders = $q->found_posts;
        $orders = array();
        $c = 0;

        while ($q->have_posts()) {
            $q->the_post();

            $orders[$c] = Factory::getComponent('shop')->getTable('order')->load(get_the_ID());
            $term_list = wp_get_post_terms(get_the_ID(), 'order_status', array("fields" => "names"));
            $orders[$c]->order_status = $term_list[0];
            $c++;
        }

        wp_reset_postdata();

        return $orders;
    }

    public function get_account_data($id) {

        $this->db->setQuery("SELECT id FROM  #_shop_orders WHERE wp_user_id =" . (int) $id . " LIMIT 1");
        if (!$this->db->getResource()) {
            throw new Exception($this->db->getErrorString());
        }
        return Factory::getComponent('shop')->getTable('order')->load((int) $this->db->loadResult());
    }

    public function update_account() {

        $customer = Factory::getComponent('shop')->getHelper('customer');

        $input = Factory::getApplication()->getInput();

        if ($customer->ship_to_billing()) {
            update_user_meta(get_current_user_id(), '_ship_to_billing', 1);
        } else {
            update_user_meta(get_current_user_id(), '_ship_to_billing', 0);
        }

        while ($field = $customer->get_billing()->get_field()) {
            if (!$field->validate($input->post)) {
                Factory::getComponent('shop')->addError("(billing) " . $field->get_error_msg());
                continue;
            }
            update_user_meta(get_current_user_id(), "_" . $field->get_name(), $field->get_value());
        }

        while ($field = $customer->get_shipping()->get_field()) {
            if (!$field->validate($input->post)) {
                Factory::getComponent('shop')->addError("(shipping) " . $field->get_error_msg());
                continue;
            }
            update_user_meta(get_current_user_id(), "_" . $field->get_name(), $field->get_value());
        }
        return true;
    }

}
