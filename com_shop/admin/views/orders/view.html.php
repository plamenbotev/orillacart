<?php
defined('_VALID_EXEC') or die('access denied');

class shopViewOrders extends view {

    public function fill_column() {
         $this->loadTemplate('listorders');
    }

    public function display() {
        Factory::getHead()->addScript('admin-list-orders', Factory::getComponent('shop')->getAssetsUrl() . "/js/admin-list-orders.js");
    }

    public function order_actions_box($post) {

        $this->assign('post', $post);
        $this->loadTemplate('order_actions_box');
    }

    public function order_data() {
        $this->loadTemplate('edit_order_form');
    }

    public function order_items() {
        $this->loadTemplate('edit_order_items');
    }

    public function order_totals() {
        $this->loadTemplate('edit_order_totals');
    }

    public function order_meta_js() {

        Factory::getHead()->addScript('jquery');
        Factory::getHead()->addScript('jquery-ui-core');
        Factory::getHead()->addStyle('jquery-calendar-css', Factory::getComponent('shop')->getAssetsUrl() . "/ui/jquery-ui-1.8.20.custom.css");
        Factory::getHead()->addScript('jquery-calendar-js', Factory::getComponent('shop')->getAssetsUrl() . "/js/jquery.ui.datepicker.js");

        //autocomplete
        Factory::getHead()->addscript('jquery-ui-autocomplete');
        Factory::getHead()->addScript('admin-edit-order', Factory::getComponent('shop')->getAssetsUrl() . "/js/edit-order.js");
        Factory::getHead()->addScript('jquery-ui-core');
        Factory::getHead()->addStyle('jquery-ui-css', Factory::getComponent('shop')->getAssetsUrl() . "/jquery.ui.css");
        Factory::getHead()->addScript('jquery-ui-dialog');
    }

    public function get_parent_list() {

        $input = Factory::getApplication()->getInput();

        $str = $input->get('str', "", "STRING");
        $exclude = $input->get('exclude', "", "STRING");
        $exclude = array_map('intval', (array) explode(',', $exclude));

        $model = $this->getModel('orders');

        $res = $model->get_parent_list($str, $exclude);
        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");
        echo json_encode($res);

        exit;
    }

    public function get_users_list() {

        $input = Factory::getApplication()->getInput();

        $str = $input->get('str', "", "STRING");

        $model = $this->getModel('orders');

        $res = $model->get_users_by_str($str);

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");
        echo json_encode($res);
        exit;
    }

    public function add_product_form() {
        ob_start();
        ?>
        <ul>
            <?php foreach ((array) $this->all_attributes as $att) { ?>
                <li>
                    <label for="property_<?php echo $att->attribute_id; ?>"> <?php echo strings::htmlentities($att->attribute_name); ?></label>
                    <select id="property_<?php echo $att->attribute_id; ?>" name='property[<?php echo $att->attribute_id; ?>]' class='property' >
                        <option></option>
                        <?php
                        if (!empty($att->properties)) {
                            foreach ((array) $att->properties as $prop) {
                                ?>
                                <option value='<?php echo $prop->property_id; ?>'><?php echo strings::htmlentities($prop->property_name); ?></option>
                            <?php } ?>
                        </select>
                    <?php } ?>
                </li>
            <?php } ?>
            <li>
                <label for="product_quantity"><?php _e("Qty:", "com_shop"); ?></label>
                <input type="text" id="product_quantity" name="product_quantity" value="1" />
            </li>
        </ul>
        <button class="btn btn-success" id="add_product_to_order">
            <i class="icon-save"></i>
            <?php _e("Save", "com_shop"); ?>
        </button>
        <?php
        header("HTTP/1.0 200 OK");
        header('Content-type: text/html; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");
        echo ob_get_clean();

        exit;
    }

    public function reload_order_files_and_totals() {

        $res = new stdClass();
        $res->items = '';
        $res->totals = '';
        $res->status = $this->status;
        ob_start();
        $this->loadTemplate('edit_order_items');
        $res->items = ob_get_contents();
        ob_clean();
        $this->loadTemplate('edit_order_totals');
        $res->totals = ob_get_contents();

        ob_end_clean();

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");
        echo json_encode($res);
        exit;
    }

}
