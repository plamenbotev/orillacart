<?php

class shopControllerAccount extends controller {

    protected function display() {

        $this->getview('account');

        if (!is_user_logged_in()) {
            parent::display('login_form');
        } else {
            $model = $this->getModel('account');
            $order = $this->getModel('order');
            $uid = get_current_user_id();
            $start = max(1, request::getInt('paged'));
            $orders = $model->get_user_orders($uid, $start);
            
            
            $total = ceil($model->get_total_user_orders() / 10);

            $pagination = paginate_links(array(
                'base' => rawurldecode(Route::get('component=shop&con=account&paged=%#%')),
                'format' => '?paged=%#%',
                'current' => max(1, request::getInt('paged')),
                'total' => $total
            ));

            $this->view->assign('pagination', $pagination);
            $this->view->assign('price', Factory::getApplication('shop')->getHelper('price'));
            $this->view->assign('orders', $orders);

            $files = $order->get_customer_files($uid);
          
            $this->view->assign('files', $files);

            $customer = Factory::getApplication('shop')->getTable('user')->load($uid);

            $this->view->assign('billing', $customer->get_billing());
            $this->view->assign('shipping', $customer->get_shipping());
            $this->view->assign('ship_to_billing', $customer->ship_to_billing());
            parent::display();
        }
    }

    protected function download() {

        $key = request::getString('order_key', '');
        $file = request::getInt('file', 0);
        $item = request::getInt('item', 0);
        $db = Factory::getDBO();

        $db->setQuery("SELECT * FROM #_shop_order_item as oi
                        INNER JOIN #_posts as o ON o.ID = oi.order_id
                        INNER JOIN #_shop_order_attribute_item AS ai ON ai.order_item_id = oi.order_item_id
                        WHERE o.post_type = 'shop_order' AND oi.order_item_id = " . $item . "  AND ai.section = 'file' AND ai.section_id = " . (int) $file . " AND o.post_password='" . $db->secure($key) . "' LIMIT 1");

        $row = $db->nextObject();

        if (!$row || empty($row->access_granted)) {
            wp_die(__("No access to that download", 'com_shop'));
            exit;
        }
        if (!is_null($row->downloads_remaining) && $row->downloads_remaining == 0) {
            wp_die(__("Sorry, you have reached maximum allowed downloads for that file.", 'com_shop'));
            exit;
        }
        if (!empty($row->expires) && $row->expires != '0000-00-00 00:00:00' && strtotime($row->expires) < current_time('timestamp')) {
            wp_die(__("Download link has expired.", 'com_shop'));
            exit;
        }
        if ($row->downloads_remaining > 0) {
            $file = Factory::getApplication('shop')->getTable('order_attribute_item')->load($row->order_att_item_id);
            $file->downloads_remaining--;
            $file->store();
        }

        $file_path = wp_get_attachment_url($row->section_id);
        $site_url = site_url();
        $network_url = network_admin_url();
        if (is_ssl()) {
            $site_url = str_replace('https:', 'http:', $site_url);
            $network_url = str_replace('https:', 'http:', $network_url);
        }
        if (!is_multisite()) {
            $file_path = str_replace(trailingslashit($site_url), ABSPATH, $file_path);
        } else {
            $upload_dir = wp_upload_dir();
            // Try to replace network url
            $file_path = str_replace(trailingslashit($network_url), ABSPATH, $file_path);
            // Now try to replace upload URL
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_path);
        }

        if (empty($file_path))
            exit;

        $file_extension = strtolower(substr(strrchr($file_path, "."), 1));
        $ctype = "application/force-download";

        foreach (get_allowed_mime_types() as $mime => $type) {
            $mimes = explode('|', $mime);
            if (in_array($file_extension, $mimes)) {
                $ctype = $type;
                break;
            }
        }
        $params = Factory::getApplication('shop')->getParams();
        switch ($params->get('download_method')) {
            case "xsendfile":

                header("Content-Type: " . $ctype . "");
                header("Content-Disposition: attachment; filename=\"" . basename($file_path) . "\";");
                if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules())) {
                    if (getcwd()) {
                        $file_path = trim(preg_replace('`^' . getcwd() . '`', '', $file_path), '/');
                    }
                    header("X-Sendfile: $file_path");
                    exit;
                } elseif (stristr(getenv('SERVER_SOFTWARE'), 'lighttpd')) {
                    header("X-Lighttpd-Sendfile: $file_path");
                    exit;
                } elseif (stristr(getenv('SERVER_SOFTWARE'), 'nginx') || stristr(getenv('SERVER_SOFTWARE'), 'cherokee')) {
                    header("X-Accel-Redirect: $file_path");
                    exit;
                }

            default:

                @session_write_close();
                if (function_exists('apache_setenv'))
                    @apache_setenv('no-gzip', 1);
                @ini_set('zlib.output_compression', 'Off');
                @set_time_limit(0);
                @set_magic_quotes_runtime(0);
                @ob_end_clean();
                if (ob_get_level())
                    @ob_end_clean(); // Zip corruption fix

                header("Pragma: no-cache");
                header("Expires: 0");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                header("Robots: none");
                header("Content-Type: " . $ctype . "");
                header("Content-Description: File Transfer");
                header("Content-Disposition: attachment; filename=\"" . basename($file_path) . "\";");
                header("Content-Transfer-Encoding: binary");

                if ($size = @filesize($file_path))
                    header("Content-Length: " . $size);

                @readfile_chunked("$file_path") or wp_die(__('File not found', 'com_shop') . ' <a href="' . home_url() . '">' . __('Go to homepage &rarr;', 'com_shop') . '</a>');

                exit;
                break;
        }
        exit;
    }

    protected function save_account() {
        $this->getView('account');
        $model = $this->getModel('account');

        try {
            if ($model->update_account()) {
                Factory::getApplication('shop')->setMessage(__("Account data has been updated!", 'com_shop'));
            }
        } catch (Exception $e) {
            Factory::getApplication('shop')->addError(__('Unable to update account!', 'com_shop'));
        }
        $this->execute();
    }

    protected function view_order() {

        $helper = Factory::getApplication('shop')->getHelper('order');
        $order = $helper->get_order(request::getInt('id', null));
        $price = Factory::getApplication('shop')->getHelper('price');
        $model = $this->getModel('order');

        $this->getView('account');
        if (!$order) {
            wp_die(__("Invalid order id.", 'com_shop'));
            exit;
        }

        $key = Request::getString('order_key', null);

        if ($key !== $order['post_password']) {
            wp_die(__("Authentication failed", 'com_shop'));
            exit;
        }

        $class = $order['payment_method'];
        if (!class_exists($class) || !is_subclass_of($class, 'payment_method')) {
            $this->view->assign('on_receipt_content', '');
        } else {
            $gateway = new $class();
            if (method_exists($gateway, 'on_receipt')) {
                $this->view->assign('on_receipt_content', (string) $gateway->on_receipt());
            } else {
                $this->view->assign('on_receipt_content', '');
            }
        }

        $this->view->assign('price', $price);
        $this->view->assign("files", $model->get_order_files((int) $order['ID']));
        $this->view->assign('items', $model->get_order_items($order['ID']));
        $this->view->assign("taxes",$helper->get_order_taxes($order['ID']));
        $this->view->assign('order', $order);
        parent::display('view_order');
    }

}